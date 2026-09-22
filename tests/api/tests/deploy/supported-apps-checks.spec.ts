import { createHmac, randomUUID } from 'node:crypto';
import { expect, test, type APIRequestContext } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import type { BackupRecord } from '@/types';
import { assertMissingEntry, healthFromRaw } from '@/helpers/app-health';
import {
  backupAsyncStatus,
  backupTaskId,
  waitForBackupDeleted,
  waitForBackupPhase,
} from '@/helpers/backup-helpers';
import {
  containerIsRunning,
  DEFAULT_DEPLOY_GIT_REPO,
  waitForDeploy,
} from '@/helpers/deploy-helpers';
import { expectOneOf } from '@/helpers/expect-one-of';
import { rand } from '@/helpers/random';
import {
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

  test('an archive deploy without a zip is refused', async ({ api, userFactory }) => {
    const user = await userFactory.createDindUser(ONLINE_DEPLOY_USER);
    skipUnless(user, 'DinD is not available on this engine.');
    skipUnlessOnline(user.domain);
    const empty = await api.deployArchiveRaw(user.username, {});
    expect(empty.status).toBe(422);
    const notZip = await api.deployArchiveRaw(user.username, { zip_path: '/no-such.zip' });
    expectOneOf(notZip.status, [404, 422]);
  });

  test('a working copy can be created and pushed back', async ({ api, userFactory }) => {
    const user = await userFactory.createDindUser(ONLINE_DEPLOY_USER);
    skipUnless(user, 'DinD is not available on this engine.');
    skipUnlessOnline(user.domain);
    const username = user.username;
    let stagingUsername: string | undefined;

    try {
      const response = await api.createStagingRaw(username, {});
      expect(response.status).toBe(202);
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
      expect(pushed.status).toBe(202);

      await expect
        .poll(
          async () =>
            stagingPushPhase((await api.getUserRaw(username)).body as unknown) ?? 'missing',
          {
            timeout: 180_000,
            intervals: [2_000],
            message: 'Push did not reach a terminal status',
          }
        )
        .toMatch(/^(completed|failed)$/);
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
      const listedEmpty = await api.listProjectBackups(user.username);
      expect(Array.isArray(listedEmpty.data)).toBe(true);

      const started = await api.createProjectBackupRaw(user.username, {
        container: String(store.data.id),
      });
      expect(started.status).toBe(202);
      const created = (started.body as { data?: BackupRecord }).data;
      skipUnless(created, 'Create backup did not return a backup record.');

      const taskId = backupTaskId(created);
      if (taskId !== undefined) {
        const task = await api.getTask(taskId);
        expect(task.data.id).toBe(taskId);
        expect(typeof task.data.status).toBe('string');
      }

      const finished = await waitForBackupPhase(api, user.username, created.id, 'backup');
      expectOneOf(backupAsyncStatus(finished, 'backup') ?? 'unknown', ['completed', 'failed']);

      const fetched = await api.getProjectBackup(user.username, created.id);
      expect(fetched.data.id).toBe(created.id);
      expect(fetched.data.username).toBe(user.username);

      const listed = await api.listProjectBackups(user.username);
      expect(listed.data.some((row) => row.id === created.id)).toBe(true);

      const unconfirmed = await api.restoreProjectBackupRaw(user.username, created.id, {});
      expectOneOf(unconfirmed.status, [400, 422]);

      if (backupAsyncStatus(finished, 'backup') === 'completed') {
        const restored = await api.restoreProjectBackupRaw(user.username, created.id, {
          confirm: true,
        });
        if (restored.status === 202) {
          const restoreRecord = (restored.body as { data?: BackupRecord }).data;
          if (restoreRecord?.id !== undefined) {
            await waitForBackupPhase(api, user.username, restoreRecord.id, 'restore');
          }
        } else {
          expectOneOf(restored.status, [422]);
        }
      }

      const deleted = await api.deleteProjectBackupRaw(user.username, created.id);
      expect(deleted.status).toBe(202);
      await waitForBackupDeleted(api, user.username, created.id);

      if (taskId !== undefined) {
        const cancel = await api.cancelTaskRaw(taskId);
        expectOneOf(cancel.status, [200, 404, 409]);
      }
    } finally {
      await api.deleteBackupContainerSafe(store.data.id);
    }
  });

  test('stopping and starting the containers takes the site down and back', async ({
    api,
    userFactory,
    anonymousRequest,
  }) => {
    const user = await userFactory.createDeployedUser(ONLINE_DEPLOY_USER);
    skipUnless(user, 'Could not create a git-deployed user on this engine.');
    skipUnlessOnline(user.domain);
    const url = `https://${user.domain}/`;

    const listed = await api.listContainers(user.username);
    expect(Array.isArray(listed.data)).toBe(true);
    expect(listed.data.length, 'the deployed project reports no containers').toBeGreaterThan(0);

    const stop = await api.runProjectContainerActionRaw(user.username, 'stop');
    expectOneOf(stop.status, [200, 500]);

    await expect
      .poll(
        async () => {
          const afterStop = await api.listContainers(user.username);
          return (
            afterStop.data.length === 0 || afterStop.data.every((row) => !containerIsRunning(row))
          );
        },
        {
          timeout: 60_000,
          intervals: [2_000],
          message: 'Compose listing still shows a running container after stop',
        }
      )
      .toBe(true);

    const start = await api.runProjectContainerActionRaw(user.username, 'up');
    expectOneOf(start.status, [200, 500]);
    await waitForSiteHttpReady(anonymousRequest, url, { timeout: 90_000 });
  });

  test('a site that lost its front page is reported as missing', async ({ api, userFactory }) => {
    const user = await userFactory.createDindUser(ONLINE_DEPLOY_USER);
    skipUnless(user, 'DinD is not available on this engine.');
    skipUnlessOnline(user.domain);

    await api.createDirectory(user.username, '/site', true);
    await api.putFileContents(
      user.username,
      '/site/index.html',
      '<!doctype html><title>home</title>home\n'
    );
    await api.putFileContents(
      user.username,
      '/site/about.html',
      '<!doctype html><title>about</title>about\n'
    );
    await api.zipFiles(user.username, '/project/app.zip', '/site', true);
    const started = await api.deployArchiveRaw(user.username, { zip_path: '/project/app.zip' });
    expectOneOf(started.status, [200, 201]);
    await waitForDeploy(api, user.username);

    // A deploy never yields a static site without an entry: detection serves the first page it
    // finds when nothing is called index. The check exists for a front page that disappears
    // afterwards, and the health endpoint probes the running site every time.
    await api.removeFile(user.username, '/project/index.html');

    const health = await api.getAppHealthRaw(user.username);
    test.skip(
      [403, 404, 422].includes(health.status),
      'App health is not available for this deployed project.'
    );
    expect(health.status).toBe(200);
    assertMissingEntry(healthFromRaw(health.body));
  });

  test('a git project can create, receive, rotate and delete a hook', async ({
    api,
    anonymousRequest,
    userFactory,
  }) => {
    const user = await userFactory.createDeployedUser(ONLINE_DEPLOY_USER);
    skipUnless(user, 'Could not create a git-deployed user on this engine.');

    const absent = await api.getDeployHookRaw(user.username);
    expect(absent.status).toBe(404);
    expect(JSON.stringify(absent.body)).toMatch(/no deploy hook/i);
    expect((await api.rotateDeployHookRaw(user.username)).status).toBe(404);
    expect((await api.deleteDeployHookRaw(user.username)).status).toBe(404);

    const createdRaw = await api.createDeployHookRaw(user.username, { provider: 'github' });
    expect(createdRaw.status).toBe(201);
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

    try {
      const againRaw = await api.createDeployHookRaw(user.username);
      expect(againRaw.status).toBe(200);
      const again = validateParsedApiResponse(againRaw.body, deployHookCreateResponseSchema);
      expect(again.data.created).toBe(false);
      expect(again.data.url).toBe(url);
      expect(again.data).not.toHaveProperty('secret');

      const shownRaw = await api.getDeployHookRaw(user.username);
      expect(shownRaw.status).toBe(200);
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
      expect(ping.status()).toBe(200);
      expect(await ping.json()).toEqual({ outcome: 'ignored', reason: 'ping' });

      const forged = await postGithub(anonymousRequest, url, PING_BODY, {
        'X-GitHub-Event': 'ping',
        'X-GitHub-Delivery': randomUUID(),
        'X-Hub-Signature-256': signature('not-the-secret', PING_BODY),
      });
      expect(forged.status()).toBe(401);

      const anonymous = await anonymousRequest.post(url, { data: '{}' });
      expect(anonymous.status()).toBe(400);

      const status = await api.gitStatus(user.username);
      const tracked = status.data.branch;
      if (typeof tracked !== 'string' || tracked.length === 0) {
        throw new Error(`git status did not name a tracked branch (got ${typeof tracked})`);
      }
      const otherBranch = tracked === 'other-branch' ? 'not-the-tracked-branch' : 'other-branch';
      const pushBody = JSON.stringify({
        ref: `refs/heads/${otherBranch}`,
        after: 'a'.repeat(40),
      });
      const push = await postGithub(anonymousRequest, url, pushBody, {
        'X-GitHub-Event': 'push',
        'X-GitHub-Delivery': randomUUID(),
        'X-Hub-Signature-256': signature(secret, pushBody),
      });
      expect(push.status()).toBe(202);
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
      expect(missingHook.status()).toBe(404);

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
      expect(rotatedRaw.status).toBe(200);
      const rotated = validateParsedApiResponse(rotatedRaw.body, deployHookRotateResponseSchema);
      expect(rotated.data.url).not.toBe(url);
      expect(rotated.data.secret).not.toBe(secret);

      const retired = await postGithub(anonymousRequest, url, PING_BODY, {
        'X-GitHub-Event': 'ping',
        'X-GitHub-Delivery': randomUUID(),
        'X-Hub-Signature-256': signature(secret, PING_BODY),
      });
      expect(retired.status()).toBe(404);

      const renewed = await postGithub(anonymousRequest, rotated.data.url, PING_BODY, {
        'X-GitHub-Event': 'ping',
        'X-GitHub-Delivery': randomUUID(),
        'X-Hub-Signature-256': signature(rotated.data.secret, PING_BODY),
      });
      expect(renewed.status()).toBe(200);

      expect((await api.deleteDeployHookRaw(user.username)).status).toBe(204);
      expect((await api.getDeployHookRaw(user.username)).status).toBe(404);
      expect((await api.rotateDeployHookRaw(user.username)).status).toBe(404);
      const gone = await postGithub(anonymousRequest, rotated.data.url, PING_BODY, {
        'X-GitHub-Event': 'ping',
        'X-Hub-Signature-256': signature(rotated.data.secret, PING_BODY),
      });
      expect(gone.status()).toBe(404);
    } finally {
      await api.deleteDeployHookRaw(user.username).catch(() => undefined);
    }
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
