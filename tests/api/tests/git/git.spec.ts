import { expect, test } from '@/fixtures/test-options';
import { configuredGitRepo } from '@/helpers/deploy-helpers';
import { expectOneOf } from '@/helpers/expect-one-of';
import { skipUnless } from '@/helpers/test-helpers';
import { expectStatus } from '@/helpers/expect-status';
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
      expect(payload.managed_by, 'the connected account is managed by site_git').toBe('site_git');
      expect(payload.connected, 'the connect response says connected').toBe(true);
      return payload;
    });

    try {
      await test.step('read the status back', async () => {
        const fetched = await api.gitStatus(user.username);
        expect(fetched.data.connected, 'the stored status says connected').toBe(true);
      });

      await test.step('pull, then browse and revert when it succeeded', async () => {
        const pulled = await api.gitPullRaw(user.username, { strategy: 'force' });
        expectOneOf(pulled.status, [200, 422]);

        if (pulled.status === 200) {
          const branches = await api.gitBranches(user.username);
          expect(Array.isArray(branches.data), 'branches is an array').toBe(true);
          const commits = await api.gitCommits(user.username, { limit: 5 });
          expect(Array.isArray(commits.data), 'commits is an array').toBe(true);
          await expectStatus(await api.gitRevertRaw(user.username), 200);
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
