import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import { parallelismFromEnv } from '@/config/parallelism';
import { deployLogSnapshotSchema } from '@/schemas';
import { assertDeploymentWarnings, requireServing } from '@/helpers/app-health';
import { waitForDeploy } from '@/helpers/deploy-helpers';
import { expectStatus } from '@/helpers/expect-status';
import { msBetween, PhaseTimer, recordBuildTimings } from '@/helpers/phase-timer';
import { expectOneOf } from '@/helpers/expect-one-of';
import { inspectGitBranch, inspectPlatform, inspectStrategy } from '@/helpers/inspect-helpers';
import { redactAndTruncate } from '@/helpers/redact';
import { waitForCondition } from '@/helpers/retry';
import { skipUnless, skipUnlessOnline } from '@/helpers/test-helpers';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';
import { fetchSite } from '@/helpers/webserver-helpers';
import { ONLINE_DEPLOY_USER } from '@/test-data/factories';
import { selectedSupportedApps, type SupportedApp } from '@/test-data/static/supported-apps';
import type { EngineApi } from '@/clients/engine-api';

/**
 * One real deploy per Supported-board app:
 *   1. POST /source/inspect  (clone + strategy; retried on a git 504)
 *   2. POST /projects        (account + deploy of that branch)
 *   3. deploy log until a terminal status, then timings with build_timings=1
 *   4. GET /app/health       (`serving: ok` — a placeholder is not support)
 *   5. GET https://<domain>/ (200/301/302, or an auth wall once serving is ok)
 *
 * The account is deleted afterwards (`preserveOnFailure: false`).
 * Run with `bun run test:supported-apps`. `SUPPORTED_APPS=koel,ntfy` limits the slice.
 * Checks that are not repeated per application live in supported-apps-checks.spec.ts.
 */
const apps = selectedSupportedApps(process.env.SUPPORTED_APPS);

test.describe('supported applications', () => {
  test.describe.configure({ timeout: Timeouts.supportedApp });
  // With TEST_PARALLEL on, the catalogue is split over the runner's supported-apps workers.
  if (parallelismFromEnv().appsWorkers > 1) {
    test.describe.configure({ mode: 'parallel' });
  }

  for (const app of apps) {
    test(`${app.stack}: ${app.title} deploys and serves`, async ({
      api,
      userFactory,
      anonymousRequest,
    }, testInfo) => {
      const phases = new PhaseTimer(testInfo);
      const inspected = await phases.run('inspect', () => inspectUntilAnswered(api, app));
      await expectStatus(inspected, 200, JSON.stringify(inspected.body));
      const branch = inspectGitBranch(inspected.body);
      const strategy = inspectStrategy(inspected.body);
      const platform = inspectPlatform(inspected.body);
      expect(branch, `${app.title} inspect did not resolve a branch`).toBeTruthy();
      expect(strategy, `${app.title} inspect did not name a strategy`).toBeTruthy();
      testInfo.annotations.push(
        { type: 'issue', description: `#${app.iid} ${app.issueUrl}` },
        { type: 'repo', description: app.repo },
        { type: 'branch', description: branch ?? '' },
        { type: 'strategy', description: `${strategy ?? ''} / ${platform ?? ''}` }
      );

      const user = await phases.run('create', () =>
        userFactory.createDeployedUser({
          ...ONLINE_DEPLOY_USER,
          git_repo: app.repo,
          git_branch: branch,
          deployTimeout: Timeouts.supportedAppDeploy,
        })
      );
      skipUnless(user, 'Could not create a git-deployed project on this engine.');
      skipUnlessOnline(user.domain);

      const shown = await api.getUser(user.username);
      expect(shown.data.details.template).toBe('dind');

      const createTask = userFactory.createTaskOf(user.username)?.task;
      const queued = msBetween(createTask?.queued_at, createTask?.started_at);
      if (queued !== undefined) {
        phases.note('queue', queued);
      }
      const ran = msBetween(
        createTask?.started_at,
        createTask?.completed_at ?? createTask?.failed_at
      );
      if (ran !== undefined) {
        phases.note('task', ran);
      }

      const { log, timed } = await phases.run('deploy', async () => {
        const finished = await waitForDeploy(api, user.username, {
          timeout: Timeouts.supportedAppDeploy,
        });
        const withTimings = await api.getDeployLog(user.username, { buildTimings: true });
        return { log: finished, timed: withTimings };
      });
      await recordBuildTimings(testInfo, timed.data.timings);
      expectOneOf(log.status, ['success', 'partial']);

      validateParsedApiResponse(timed.data, deployLogSnapshotSchema);
      expect(timed.data.timings, 'a finished deploy records timings').toBeTruthy();
      expect(Array.isArray(timed.data.timings?.phases)).toBe(true);
      assertDeploymentWarnings(
        shown.data.details.deployment_status ?? log.status,
        shown.data.details.deployment_warnings
      );

      const health = await phases.run('health', () => api.getAppHealthRaw(user.username));
      await expectStatus(health, 200, JSON.stringify(health.body));
      const report = requireServing(health.body);
      expect(report.serving, `${app.title} answered, but not as the application`).toBe('ok');

      const site = await phases.run('http', () =>
        fetchSite(anonymousRequest, `https://${user.domain}/`, {
          timeout: 60_000,
        })
      );
      const code = site.status();
      testInfo.annotations.push({ type: 'http', description: String(code) });
      // serving is ok here, so an application's own auth wall is a valid answer.
      expectOneOf(code, [200, 301, 302, 303, 307, 308, 401, 403]);

      // The fixture would delete this account anyway; doing it here times it.
      await phases.run('cleanup', () => userFactory.deleteUser(user.username));
      testInfo.annotations.push({ type: 'phases', description: phases.summary() });
    });
  }
});

async function inspectUntilAnswered(
  api: EngineApi,
  app: SupportedApp
): Promise<{ status: number; body: unknown }> {
  let latest: { status: number; body: unknown } = { status: 0, body: {} };
  await waitForCondition(
    async () => {
      latest = await api.inspectSourceRaw({ source: app.repo, type: 'git' }, { timeout: 180_000 });
      if (latest.status === 200) {
        return true;
      }
      const message = messageOf(latest.body);
      return !message.includes('504');
    },
    {
      timeout: 720_000,
      interval: 15_000,
      message: `Inspect of ${app.repo} kept failing with HTTP 504`,
      describeLast: () =>
        `HTTP ${latest.status}, body ${redactAndTruncate(JSON.stringify(latest.body), 300)}`,
    }
  );
  return latest;
}

function messageOf(body: unknown): string {
  if (body !== null && typeof body === 'object' && 'message' in body) {
    const message = (body as { message?: unknown }).message;
    return typeof message === 'string' ? message : '';
  }
  return '';
}
