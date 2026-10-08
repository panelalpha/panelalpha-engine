import { expect, test } from '@/fixtures/test-options';
import type { EngineApi } from '@/clients/engine-api';
import type { UserUsage } from '@/types/system.types';
import { rand, randomDomain } from '@/helpers/random';
import { waitForCondition } from '@/helpers/retry';
import { sharedProject } from '@/helpers/shared-project';

/**
 * Every countable resource follows the same contract: creating one raises the
 * matching usage counter, and the counter is readable through `GET /projects/<u>/usage`.
 */
interface CountableResource {
  /** The key in the usage payload this resource is counted under. */
  counter: keyof UserUsage;
  /** Creates one instance and returns the identifier needed to delete it. */
  create: (
    api: EngineApi,
    user: { username: string; domain: string },
    baseDomain: string
  ) => Promise<string>;
  remove: (api: EngineApi, username: string, id: string) => Promise<unknown>;
}

const RESOURCES: Record<string, CountableResource> = {
  'an addon domain': {
    counter: 'addon_domains',
    create: async (api, user, baseDomain) => {
      const domain = randomDomain(baseDomain);
      await api.createDomain(user.username, { domain, type: 'addon' });
      return domain;
    },
    remove: (api, username, domain) => api.deleteDomain(username, domain),
  },
  'an FTP account': {
    counter: 'ftp_accounts',
    create: async (api, user) => {
      const name = rand('ftp');
      await api.createFtpAccount(user.username, {
        user: name,
        domain: user.domain,
        password: 'TestFtp123!',
      });
      return `${name}@${user.domain}`;
    },
    remove: (api, username, account) => api.deleteFtpAccount(username, account),
  },
  'an SFTP account': {
    counter: 'sftp_accounts',
    create: async (api, user) => {
      const username = `${user.username}_${rand('sftp')}`;
      await api.createSftpAccount(user.username, {
        username,
        auth_method: 'password',
        password: 'TestSftp123!',
      });
      return username;
    },
    remove: (api, username, account) => api.deleteSftpAccount(username, account),
  },
  'a MySQL database': {
    counter: 'mysql_databases',
    create: async (api, user) => {
      const requested = rand('testdb');
      const { data } = await api.createMySqlDatabase(user.username, requested);
      // The engine prefixes the name it actually created; delete needs that one.
      return data.database ?? requested;
    },
    remove: (api, username, database) => api.deleteMySqlDatabase(username, database),
  },
};

test.describe('usage counters', () => {
  // One project for the file: each test creates its resource, reads its own counter and removes
  // the resource again, and no two tests read the same counter.
  const shared = sharedProject('simple');
  test.afterAll(() => shared.dispose());

  for (const [label, resource] of Object.entries(RESOURCES)) {
    test(`creating ${label} raises ${resource.counter}.usage`, async ({ api, settings }) => {
      const user = await shared.get();

      const before = await test.step('read the counter before', async () => {
        return (await api.getUserUsage(user.username))[resource.counter].usage;
      });
      const id = await test.step('create the resource', () =>
        resource.create(api, user, settings.requireDomain()));

      try {
        await test.step('wait for the counter to rise', async () => {
          let observed: number | undefined;
          await waitForCondition(
            async () => {
              observed = (await api.getUserUsage(user.username))[resource.counter].usage;
              return observed > before;
            },
            {
              timeout: 30_000,
              interval: 2_000,
              message: `${resource.counter}.usage stayed at ${before} after creating ${label}`,
              describeLast: () => `${resource.counter}.usage read ${observed ?? '(none)'}`,
            }
          );
        });
      } finally {
        await test.step('remove the resource', async () => {
          await resource.remove(api, user.username, id);
        });
      }
    });
  }

  test('storage usage is a non-negative number before and after a write', async ({ api }) => {
    const user = await shared.get();
    const path = `/${user.domain}/public_html/usage_test_${rand()}.bin`;

    await test.step('read storage usage before the write', async () => {
      expect(
        (await api.getUserUsage(user.username)).storage.usage,
        'storage usage before the write'
      ).toBeGreaterThanOrEqual(0);
    });

    await test.step('write a 1 MiB file', async () => {
      await api.putFileContents(user.username, path, 'X'.repeat(1024 * 1024));
    });

    try {
      await test.step('read storage usage after the write', async () => {
        expect(
          (await api.getUserUsage(user.username)).storage.usage,
          'storage usage after the write'
        ).toBeGreaterThanOrEqual(0);
      });
    } finally {
      await test.step('remove the file', async () => {
        await api.removeFile(user.username, path);
      });
    }
  });
});
