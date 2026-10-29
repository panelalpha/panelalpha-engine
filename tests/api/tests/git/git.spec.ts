import { expect, test } from '@/fixtures/test-options';
import { configuredGitRepo } from '@/helpers/deploy-helpers';
import { expectOneOf } from '@/helpers/expect-one-of';
import { taskIdFromBody, waitForTask } from '@/helpers/task-helpers';
import { skipUnless } from '@/helpers/test-helpers';
import { expectStatus } from '@/helpers/expect-status';
import { testNetwork } from '@/helpers/test-network';
import { Timeouts } from '@/config/timeouts';
import type { GitStatus } from '@/types';

test.describe('git API', () => {
  test('an unknown project is 404', async ({ api }) => {
    await expectStatus(await api.gitStatusRaw('nosuchuser999'), 404);
  });

  test('connect without a repo URL is refused', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser();
    const response = await api.gitConnectRaw(user.username, { branch: 'main' });
    expectOneOf(response.status, [400, 422]);
  });

  test('connect refuses an internal or unresolvable host', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser();
    for (const repoUrl of [
      `http://${testNetwork.LOOPBACK}:9/x.git`,
      `http://${testNetwork.METADATA}/x.git`,
      'http://[::1]/x.git',
      'http://0x7f000001:9/x.git',
      'https://no-such-host.invalid/o/r.git',
    ]) {
      const response = await api.gitConnectRaw(user.username, {
        repo_url: repoUrl,
        branch: 'main',
      });
      await expectStatus(response, 422);
      const body = response.body as { problems?: { field?: string; code?: string }[] };
      expect(body.problems?.[0]?.field, repoUrl).toBe('repo_url');
      expect(body.problems?.[0]?.code, repoUrl).toBe('host_refused');
    }
    const status = await api.gitStatus(user.username);
    expect(status.data.connected, 'nothing was connected').toBe(false);
  });

  test('a PHP account reports site_git and is not connected', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser();
    await test.step('read the git status', async () => {
      const status = await api.gitStatus(user.username);
      expect(status.data.managed_by, 'a PHP account is managed by site_git').toBe('site_git');
      expect(status.data.connected, 'the account is not connected to a repo').toBe(false);
    });
    await test.step('pull is refused', async () => {
      await expectStatus(await api.gitPullRaw(user.username), 422);
    });
    await test.step('disconnect is refused', async () => {
      await expectStatus(await api.gitDisconnectRaw(user.username), 422);
    });
  });

  test('an empty DinD project can connect, pull and disconnect', async ({ api, userFactory }) => {
    test.setTimeout(Timeouts.deploy);
    const user = await userFactory.createDindUser();
    skipUnless(user, 'DinD is not available on this engine.');

    const repo = configuredGitRepo();
    await test.step('connect the repository', async () => {
      const connected = await api.gitConnectRaw(user.username, {
        repo_url: repo,
        branch: 'main',
      });
      await expectStatus(connected, 200);
      const payload = (connected.body as { data?: GitStatus }).data;
      skipUnless(payload, 'git/connect returned no status payload.');
      // A DinD project connected with git_connect is deployed from git (engine#817).
      expect(payload.managed_by, 'the connected project is deploy-managed').toBe('deploy');
      expect(payload.connected, 'the connect response says connected').toBe(true);
      return payload;
    });

    try {
      await test.step('read the status back', async () => {
        const fetched = await api.gitStatus(user.username);
        expect(fetched.data.connected, 'the stored status says connected').toBe(true);
      });

      // Deploy-managed: a pull or revert is queued with its rebuild (202) and
      // holds the project until it ends, so each one is followed to the end.
      const settled = async (response: { status: number; body: unknown }): Promise<void> => {
        const id = response.status === 202 ? taskIdFromBody(response.body) : undefined;
        if (id !== undefined) {
          await waitForTask(api, id);
        }
      };

      await test.step('pull, then browse and revert when it succeeded', async () => {
        const pulled = await api.gitPullRaw(user.username, { strategy: 'force' });
        expectOneOf(pulled.status, [200, 202, 422]);
        await settled(pulled);

        if (pulled.status !== 422) {
          const branches = await api.gitBranches(user.username);
          expect(Array.isArray(branches.data), 'branches is an array').toBe(true);
          const commits = await api.gitCommits(user.username, { limit: 5 });
          expect(Array.isArray(commits.data), 'commits is an array').toBe(true);
          const reverted = await api.gitRevertRaw(user.username);
          expectOneOf(reverted.status, [200, 202]);
          await settled(reverted);
          const credentials = await api.gitUpdateCredentials(user.username, {});
          expect(credentials.data.connected, 'still connected after updating credentials').toBe(
            true
          );
        }
      });

      await test.step('change-branch without a branch is refused', async () => {
        const missingBranch = await api.gitChangeBranchRaw(user.username, {});
        expectOneOf(missingBranch.status, [400, 422]);
      });
    } finally {
      await test.step('disconnect the repository', async () => {
        const disconnected = await api.gitDisconnectRaw(user.username);
        expectOneOf(disconnected.status, [200, 422]);
      });
    }
  });
});
