import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import { deployLogSnapshotSchema } from '@/schemas';
import { assertDeploymentWarnings, healthFromRaw } from '@/helpers/app-health';
import { waitForDeploy } from '@/helpers/deploy-helpers';
import { expectOneOf } from '@/helpers/expect-one-of';
import { inspectGitBranch, inspectPlatform, inspectStrategy } from '@/helpers/inspect-helpers';
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
 * Run with `npm run test:supported-apps`. `SUPPORTED_APPS=koel,ntfy` limits the slice.
 * Checks that are not repeated per application live in supported-apps-checks.spec.ts.
 */
const apps = selectedSupportedApps(process.env.SUPPORTED_APPS);

test.describe('supported applications', () => {
  test.describe.configure({ timeout: Timeouts.supportedApp });

  for (const app of apps) {
    test(`${app.stack}: ${app.title} deploys and serves`, async ({
      api,
      userFactory,
      anonymousRequest,
    }, testInfo) => {
      const inspected = await inspectUntilAnswered(api, app);
      expect(inspected.status, JSON.stringify(inspected.body)).toBe(200);
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

      const user = await userFactory.createDeployedUser({
        ...ONLINE_DEPLOY_USER,
        git_repo: app.repo,
        git_branch: branch,
        deployTimeout: Timeouts.supportedAppDeploy,
      });
      skipUnless(user, 'Could not create a git-deployed project on this engine.');
      skipUnlessOnline(user.domain);

      const shown = await api.getUser(user.username);
      expect(shown.data.details.template).toBe('dind');

      const log = await waitForDeploy(api, user.username, {
        timeout: Timeouts.supportedAppDeploy,
      });
      expectOneOf(log.status, ['success', 'partial']);

      const timed = await api.getDeployLog(user.username, { buildTimings: true });
      validateParsedApiResponse(timed.data, deployLogSnapshotSchema);
      expect(timed.data.timings, 'a finished deploy records timings').toBeTruthy();
      expect(Array.isArray(timed.data.timings?.phases)).toBe(true);
      assertDeploymentWarnings(
        shown.data.details.deployment_status ?? log.status,
        shown.data.details.deployment_warnings
      );

      const health = await api.getAppHealthRaw(user.username);
      expect(health.status, JSON.stringify(health.body)).toBe(200);
      const serving = servingWord(health.body);
      if (serving !== undefined) {
        expect(serving, `${app.title} answered, but not as the application`).toBe('ok');
        healthFromRaw(health.body);
      }

      const site = await fetchSite(anonymousRequest, `https://${user.domain}/`, {
        timeout: 60_000,
      });
      const code = site.status();
      testInfo.annotations.push({ type: 'http', description: String(code) });
      if (serving === 'ok') {
        expectOneOf(code, [200, 301, 302, 303, 307, 308, 401, 403]);
      } else {
        expectOneOf(code, [200, 301, 302]);
      }
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

function servingWord(body: unknown): string | undefined {
  if (body === null || typeof body !== 'object' || !('data' in body)) {
    return undefined;
  }
  const data = (body as { data?: unknown }).data;
  if (data === null || typeof data !== 'object' || !('serving' in data)) {
    return undefined;
  }
  const serving = (data as { serving?: unknown }).serving;
  return typeof serving === 'string' && serving.length > 0 ? serving : undefined;
}
