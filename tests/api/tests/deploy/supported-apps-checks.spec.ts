import { createHmac, randomUUID } from 'node:crypto';
import { expect, test, type APIRequestContext } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import type { BackupRecord } from '@/types';
import { assertMissingEntry, healthFromRaw } from '@/helpers/app-health';
import {
  backupTaskId,
  expectBackupPhaseCompleted,
  waitForBackupDeleted,
  waitForBackupPhase,
} from '@/helpers/backup-helpers';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { containerIsRunning, DEFAULT_DEPLOY_GIT_REPO } from '@/helpers/deploy-helpers';
import { expectOneOf } from '@/helpers/expect-one-of';
import { expectStatus } from '@/helpers/expect-status';
import { rand } from '@/helpers/random';
import { redactAndTruncate } from '@/helpers/redact';
import { taskIdFromBody } from '@/helpers/task-helpers';
import {
  stagingPushError,
  stagingPushPhase,
  stagingUserPayload,
  waitUntilStagingActive,
} from '@/helpers/staging-helpers';
import { skipUnless, skipUnlessOnline } from '@/helpers/test-helpers';
import {
  deployHookCreateResponseSchema,
  deployHookRotateResponseSchema,
  deployHookShowResponseSchema,
} from '@/schemas';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';
import { fetchSite, waitForSiteHttpReady } from '@/helpers/webserver-helpers';
import { onlineLabel } from '@/config/site-domain';
import { ONLINE_DEPLOY_USER } from '@/test-data/factories';

/**
 * Once-per-run checks that the catalogue does not repeat for every application:
 * a refused create, an archive with no zip, a working copy pushed back to live,
 * a backup, stopping and starting the containers, a site that lost its front
 * page, a git hook, and WordPress on an ordinary account.
 */

const PING_BODY = '{"zen":"ping"}';

function signature(secret: string, body: string): string {
  return `sha256=${createHmac('sha256', secret).update(body).digest('hex')}`;
}

async function postGithub(
  request: APIRequestContext,
  url: string,
  body: string,
  headers: Record<string, string>
) {
  return request.post(url, {
    data: body,
    headers: { 'Content-Type': 'application/json', ...headers },
  });
}

/**
 * Runs one former test as a step of a merged one. A failure is kept and the
 * next step still runs; {@link rethrowFailures} reports them at the end.
 */
async function collectingFailure(
  failures: Error[],
  title: string,
  body: () => Promise<void>
): Promise<void> {
  try {
    await test.step(title, body);
  } catch (error) {
    failures.push(error instanceof Error ? error : new Error(String(error)));
  }
}

/** Fails the test with the first collected failure; later ones are recorded beside it. */
function rethrowFailures(failures: Error[]): void {
  for (const failure of failures.slice(1)) {
    expect.soft(false, failure.message).toBe(true);
  }
  if (failures.length > 0) {
    throw failures[0];
  }
}

test.describe('around a deployed application', () => {
  test.describe.configure({ timeout: Timeouts.deploy });

  test('a token without an address is refused', async ({ api, settings }) => {
    const username = `gt${Date.now().toString(36).slice(-8)}`;
    const domain = `${username}.${settings.requireDomain()}`;
    const response = await api.createUserRaw({
      username,
      domain,
      git_token: 'not-a-real-token',
    });
    expectOneOf(response.status, [400, 422]);
    await api.deleteUserSafe(username);
  });

  test('a bad account name with a repository is refused', async ({ api, settings }) => {
    const response = await api.createUserRaw({
      username: 'A',
      domain: `bad.${settings.requireDomain()}`,
      git_repo: DEFAULT_DEPLOY_GIT_REPO,
    });
    expectOneOf(response.status, [400, 422]);
  });

  test('a working copy can be created and pushed back', async ({ api, userFactory }) => {
    const user = await userFactory.createDindUser(ONLINE_DEPLOY_USER);
    skipUnless(user, 'DinD is not available on this engine.');
    skipUnlessOnline(user.domain);
    const username = user.username;
    let stagingUsername: string | undefined;

    try {
      const response = await api.createStagingRaw(username, {});
      await expectStatus(response, 202);
      const staged = stagingUserPayload(response.body as unknown);
      expect(staged.status).toBe('pending');
      expect(staged.staging_of).toBe(username);
      stagingUsername = staged.username;
      expect(stagingUsername).toBeTruthy();

      await waitUntilStagingActive(api, stagingUsername!);

      const active = await api.getUser(stagingUsername!);
      expect(active.data.status).toBe('active');
      expect(active.data.staging_of).toBe(username);

      const pushed = await api.pushProjectRaw(stagingUsername!, username);
      await expectStatus(pushed, 202);

      await expect
        .poll(
          async () => {
            const phase =
              stagingPushPhase((await api.getUserRaw(username)).body as unknown) ?? 'missing';
            return phase === 'completed' || phase === 'failed' ? 'terminal' : phase;
          },
          {
            timeout: 180_000,
            intervals: [2_000],
            message: 'Push did not reach a terminal status',
          }
        )
        .toBe('terminal');

      const settled = (await api.getUserRaw(username)).body as unknown;
      expect(
        stagingPushPhase(settled),
        `push failed: ${redactAndTruncate(stagingPushError(settled) ?? 'the target recorded no error', 600)}`
      ).toBe('completed');
    } finally {
      if (stagingUsername) {
        await api.deleteUserSafe(stagingUsername);
      }
      await api.deleteUserSafe(username);
    }
  });

  test('a project can be backed up, restored and the copy removed', async ({
    api,
    userFactory,
  }) => {
    const user = await userFactory.createDindUser();
    skipUnless(user, 'DinD is not available on this engine.');

    const name = `pa-api-${rand('pabk')}`;
    const store = await api.createBackupContainer({
      name,
      driver: 'local',
      location: `/var/tmp/${name}`,
    });

    try {
      // Written before the backup: the restore has to bring this content back.
      const marker = '/project/pa-backup-marker.txt';
      const before = `before-${rand('mk')}`;
      await api.putFileContents(user.username, marker, before);

      const listedEmpty = await api.listProjectBackups(user.username);
      expect(Array.isArray(listedEmpty.data)).toBe(true);

      const started = await api.createProjectBackupRaw(user.username, {
        container: String(store.data.id),
      });
      await expectStatus(started, 202);
      const created = (started.body as { data?: BackupRecord }).data;
      if (created === undefined || typeof created.id !== 'number') {
        throw new Error(
          `Create backup answered 202 without a backup record: ${redactAndTruncate(JSON.stringify(started.body), 400)}`
        );
      }

      const taskId = backupTaskId(created);
      if (taskId !== undefined) {
        const task = await api.getTask(taskId);
        expect(task.data.id).toBe(taskId);
        expect(typeof task.data.status).toBe('string');
      }

      const finished = await waitForBackupPhase(api, user.username, created.id, 'backup');
      await expectBackupPhaseCompleted(api, user.username, finished, 'backup', taskId);

      // Changed after the backup: the restore has to undo this.
      const after = `after-${rand('mk')}`;
      await api.putFileContents(user.username, marker, after);
      expect(await api.getFileContent(user.username, marker)).toBe(after);

      const fetched = await api.getProjectBackup(user.username, created.id);
      expect(fetched.data.id).toBe(created.id);
      expect(fetched.data.username).toBe(user.username);

      const listed = await api.listProjectBackups(user.username);
      expect(listed.data.some((row) => row.id === created.id)).toBe(true);

      const unconfirmed = await api.restoreProjectBackupRaw(user.username, created.id, {});
      // BackupRestoreRequest::rules() makes `confirm` required|accepted, so an empty body
      // fails form validation: 422 (BackupRestoreRequest.php, 'confirm').
      await expectStatus(unconfirmed, 422);

      // A completed backup with items is restorable; the only 422 is "Backup is not complete"
      // (BackupController.php:180).
      const restored = await api.restoreProjectBackupRaw(user.username, created.id, {
        confirm: true,
      });
      await expectStatus(restored, 202);
      const restoreRecord = (restored.body as { data?: BackupRecord }).data;
      expect(restoreRecord?.id, 'the restore did not return the backup record').toBe(created.id);
      const restoreDone = await waitForBackupPhase(api, user.username, created.id, 'restore');
      await expectBackupPhaseCompleted(
        api,
        user.username,
        restoreDone,
        'restore',
        backupTaskId(restoreRecord)
      );
      expect(
        await api.getFileContent(user.username, marker),
        'the restore did not bring back the content written before the backup'
      ).toBe(before);

      const deleted = await api.deleteProjectBackupRaw(user.username, created.id);
      await expectStatus(deleted, 202);
      await waitForBackupDeleted(api, user.username, created.id);

      if (taskId !== undefined) {
        const cancel = await api.cancelTaskRaw(taskId);
        // The backup's task finished before the restore ran, so it is terminal and
        // TaskCanceller::cancel() answers 'Task cannot be cancelled' -> 409
        // (TaskController::cancel :248-250, TaskCanceller.php:27-32). The row is still there.
        await expectStatus(cancel, 409);
      }
    } finally {
      await api.deleteBackupContainerSafe(store.data.id);
    }
  });

  test('a site that lost its front page is reported as missing', async ({ api, userFactory }) => {
    const user = await userFactory.createDindUser(ONLINE_DEPLOY_USER);
    skipUnless(user, 'DinD is not available on this engine.');
    skipUnlessOnline(user.domain);

    const failures: Error[] = [];
    await collectingFailure(failures, 'an archive deploy without a zip is refused', async () => {
      const empty = await api.deployArchiveRaw(user.username, {});
      await expectStatus(empty, 422);
      const notZip = await api.deployArchiveRaw(user.username, { zip_path: '/no-such.zip' });
      expectOneOf(notZip.status, [404, 422]);
    });

    // The home root is root-owned; /project is the account's writable tree, and the
    // archive deploy replaces it, so staging there leaves nothing behind.
    await api.createDirectory(user.username, '/project/site', true);
    await api.putFileContents(
      user.username,
      '/project/site/index.html',
      '<!doctype html><title>home</title>home\n'
    );
    await api.putFileContents(
      user.username,
      '/project/site/about.html',
      '<!doctype html><title>about</title>about\n'
    );
    await api.zipFiles(user.username, '/project/app.zip', '/project/site', true);
    const started = await api.deployArchiveRaw(user.username, { zip_path: '/project/app.zip' });
    await expectStatus(started, 202);
    const taskId = taskIdFromBody(started.body);
    expect(taskId).toEqual(expect.any(Number));
    // A second deploy while this one is queued or running names it instead of starting.
    const again = await api.rebuildUserRaw(user.username);
    await expectStatus(again, 409);
    expect((again.body as { task_id?: unknown }).task_id).toBe(taskId);
    await api.followDeployTask(started.body, 'POST deploy-archive');

    // A deploy never yields a static site without an entry: detection serves the first page it
    // finds when nothing is called index. The check exists for a front page that disappears
    // afterwards, and the health endpoint probes the running site every time.
    await api.removeFile(user.username, '/project/index.html');

    const health = await api.getAppHealthRaw(user.username);
    test.skip(
      [403, 404, 422].includes(health.status),
      'App health is not available for this deployed project.'
    );
    await expectStatus(health, 200);
    assertMissingEntry(healthFromRaw(health.body));
    rethrowFailures(failures);
  });

  /**
   * One deployed git project serves both: the hook scenario redeploys nothing and
   * removes its hook, and the container stop/start runs last on the untouched
   * checkout.
   */
  test('a deployed git project keeps a working hook and survives stopping and starting its containers', async ({
    api,
    anonymousRequest,
    userFactory,
  }) => {
    const user = await userFactory.createDeployedUser(ONLINE_DEPLOY_USER);
    skipUnless(user, 'Could not create a git-deployed user on this engine.');

    const failures: Error[] = [];

    await collectingFailure(
      failures,
      'a git project can create, receive, rotate and delete a hook',
      async () => {
        const absent = await api.getDeployHookRaw(user.username);
        await expectStatus(absent, 404);
        expect(JSON.stringify(absent.body)).toMatch(/no deploy hook/i);
        await expectStatus(await api.rotateDeployHookRaw(user.username), 404);
        await expectStatus(await api.deleteDeployHookRaw(user.username), 404);

        const createdRaw = await api.createDeployHookRaw(user.username, { provider: 'github' });
        await expectStatus(createdRaw, 201);
        const created = validateParsedApiResponse(createdRaw.body, deployHookCreateResponseSchema);
        expect(created.data.created).toBe(true);
        expect(created.data.secret).toEqual(expect.any(String));
        expect(created.data.warning ?? '').toMatch(/force-updates the checkout/i);
        expect(created.data.url).toMatch(/\/hooks\/[A-Za-z0-9]{16,64}$/);
        if (created.data.tls.state === 'self_signed') {
          expect(Object.keys(created.data.tls.instructions ?? {})).toEqual(['github']);
        } else {
          expect(created.data.tls.instructions).toBeNull();
        }
        const secret = created.data.secret!;
        const url = created.data.url;

        const cleanup = new CleanupSteps();
        try {
          const againRaw = await api.createDeployHookRaw(user.username);
          await expectStatus(againRaw, 200);
          const again = validateParsedApiResponse(againRaw.body, deployHookCreateResponseSchema);
          expect(again.data.created).toBe(false);
          expect(again.data.url).toBe(url);
          expect(again.data).not.toHaveProperty('secret');

          const shownRaw = await api.getDeployHookRaw(user.username);
          await expectStatus(shownRaw, 200);
          const shown = validateParsedApiResponse(shownRaw.body, deployHookShowResponseSchema);
          expect(shown.data).not.toHaveProperty('secret');
          expect(shown.data.url).toBe(url);
          expect(shown.data.registered_url).toBe(url);
          expect(shown.data.url_changed_since_registration).toBe(false);
          expect(shown.data.deliveries).toEqual([]);

          const ping = await postGithub(anonymousRequest, url, PING_BODY, {
            'X-GitHub-Event': 'ping',
            'X-GitHub-Delivery': randomUUID(),
            'X-Hub-Signature-256': signature(secret, PING_BODY),
          });
          await expectStatus(ping, 200);
          expect(await ping.json()).toEqual({ outcome: 'ignored', reason: 'ping' });

          const forged = await postGithub(anonymousRequest, url, PING_BODY, {
            'X-GitHub-Event': 'ping',
            'X-GitHub-Delivery': randomUUID(),
            'X-Hub-Signature-256': signature('not-the-secret', PING_BODY),
          });
          await expectStatus(forged, 401);

          const anonymous = await anonymousRequest.post(url, { data: '{}' });
          await expectStatus(anonymous, 400);

          const status = await api.gitStatus(user.username);
          const tracked = status.data.branch;
          if (typeof tracked !== 'string' || tracked.length === 0) {
            throw new Error(`git status did not name a tracked branch (got ${typeof tracked})`);
          }
          const otherBranch =
            tracked === 'other-branch' ? 'not-the-tracked-branch' : 'other-branch';
          const pushBody = JSON.stringify({
            ref: `refs/heads/${otherBranch}`,
            after: 'a'.repeat(40),
          });
          const push = await postGithub(anonymousRequest, url, pushBody, {
            'X-GitHub-Event': 'push',
            'X-GitHub-Delivery': randomUUID(),
            'X-Hub-Signature-256': signature(secret, pushBody),
          });
          await expectStatus(push, 202);
          expect(await push.json()).toEqual({
            outcome: 'ignored',
            reason: `branch ${otherBranch} is not the tracked branch ${tracked}`,
          });

          const unknown = new URL(url);
          unknown.pathname = `/hooks/${'a'.repeat(32)}`;
          const missingHook = await postGithub(anonymousRequest, unknown.toString(), PING_BODY, {
            'X-GitHub-Event': 'ping',
            'X-Hub-Signature-256': signature(secret, PING_BODY),
          });
          await expectStatus(missingHook, 404);

          const after = validateParsedApiResponse(
            (await api.getDeployHookRaw(user.username)).body,
            deployHookShowResponseSchema
          );
          const outcomes = after.data.deliveries.map(
            (delivery) => `${delivery.outcome}:${delivery.reason}`
          );
          expect(outcomes).toEqual(
            expect.arrayContaining([
              'ignored:ping',
              'rejected:missing or invalid signature',
              'rejected:unsupported provider',
              `ignored:branch ${otherBranch} is not the tracked branch ${tracked}`,
            ])
          );

          const rotatedRaw = await api.rotateDeployHookRaw(user.username);
          await expectStatus(rotatedRaw, 200);
          const rotated = validateParsedApiResponse(
            rotatedRaw.body,
            deployHookRotateResponseSchema
          );
          expect(rotated.data.url).not.toBe(url);
          expect(rotated.data.secret).not.toBe(secret);

          const retired = await postGithub(anonymousRequest, url, PING_BODY, {
            'X-GitHub-Event': 'ping',
            'X-GitHub-Delivery': randomUUID(),
            'X-Hub-Signature-256': signature(secret, PING_BODY),
          });
          await expectStatus(retired, 404);

          const renewed = await postGithub(anonymousRequest, rotated.data.url, PING_BODY, {
            'X-GitHub-Event': 'ping',
            'X-GitHub-Delivery': randomUUID(),
            'X-Hub-Signature-256': signature(rotated.data.secret, PING_BODY),
          });
          await expectStatus(renewed, 200);

          await expectStatus(await api.deleteDeployHookRaw(user.username), 204);
          await expectStatus(await api.getDeployHookRaw(user.username), 404);
          await expectStatus(await api.rotateDeployHookRaw(user.username), 404);
          const gone = await postGithub(anonymousRequest, rotated.data.url, PING_BODY, {
            'X-GitHub-Event': 'ping',
            'X-Hub-Signature-256': signature(rotated.data.secret, PING_BODY),
          });
          await expectStatus(gone, 404);
        } catch (error) {
          cleanup.bodyFailed(error);
          throw error;
        } finally {
          await cleanup.run('delete the deploy hook', () => api.deleteDeployHookRaw(user.username));
          await cleanup.finish();
        }
      }
    );

    // The hook scenario never needed an allocated online name; only this one does.
    if (onlineLabel(user.domain)) {
      await collectingFailure(
        failures,
        'stopping and starting the containers takes the site down and back',
        async () => {
          const siteUrl = `https://${user.domain}/`;

          const listed = await api.listContainers(user.username);
          expect(Array.isArray(listed.data)).toBe(true);
          expect(listed.data.length, 'the deployed project reports no containers').toBeGreaterThan(
            0
          );

          // 500 is the Engine reporting a non-zero compose exit (ContainerController.php:91), a
          // failed stop, not an alternative outcome.
          const stop = await api.runProjectContainerActionRaw(user.username, 'stop');
          await expectStatus(stop, 200);

          await expect
            .poll(
              async () => {
                const afterStop = await api.listContainers(user.username);
                const running = afterStop.data.filter((row) => containerIsRunning(row));
                if (running.length === 0) {
                  return 'met';
                }
                return `not met: ${running.length} running, ${redactAndTruncate(JSON.stringify(running), 300)}`;
              },
              {
                timeout: 60_000,
                intervals: [2_000],
                message: 'Compose listing still shows a running container after stop',
              }
            )
            .toBe('met');

          const start = await api.runProjectContainerActionRaw(user.username, 'up');
          await expectStatus(start, 200);
          await waitForSiteHttpReady(anonymousRequest, siteUrl, { timeout: 90_000 });
        }
      );
    } else {
      test.info().annotations.push({
        type: 'skipped step',
        description: `stopping and starting the containers: PanelAlpha Online was not allocated (${user.domain}).`,
      });
    }

    rethrowFailures(failures);
  });

  test('WordPress can be installed on an ordinary account', async ({
    api,
    userFactory,
    wordpressFactory,
    anonymousRequest,
  }) => {
    const credentials = await userFactory.createUser(ONLINE_DEPLOY_USER);
    skipUnlessOnline(credentials.domain);
    const wordpress = await wordpressFactory.installWordPress(credentials);

    const installed = await api.executeWpCliCommand(credentials.username, [
      'core',
      'is-installed',
      `--path=${wordpress.wpPath}`,
    ]);
    expect(installed.exit_code).toBe(0);

    const url = `https://${credentials.domain}/`;
    await waitForSiteHttpReady(anonymousRequest, url, {
      timeout: 90_000,
      requireSuccess: true,
    });
    const page = await fetchSite(anonymousRequest, url);
    const body = await page.text();
    expect(body, body.slice(0, 500)).toMatch(/wp-content|wp-includes|WordPress|wp-login/i);
  });
});
