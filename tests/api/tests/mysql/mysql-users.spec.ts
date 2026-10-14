import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { UPDATED_DB_PASSWORD, createdMySqlName } from '@/helpers/mysql-helpers';
import { rand } from '@/helpers/random';
import { redactAndTruncate } from '@/helpers/redact';
import { waitForCondition } from '@/helpers/retry';
import { expectStatus } from '@/helpers/expect-status';

test.describe('MySQL users', () => {
  test('the user list is returned', { tag: ['@smoke'] }, async ({ api, setupUser }) => {
    expect(Array.isArray((await api.listMySqlUsers(setupUser.username)).data)).toBe(true);
  });

  /**
   * The full account lifecycle in one pass. Each step needs the name the engine
   * assigned in the previous one — renaming changes it — so this stays a single
   * test rather than a chain of independent ones.
   */
  test('a user is created, renamed, given a new password, then deleted', async ({
    api,
    mysqlFactory,
    settings,
    setupUser,
  }) => {
    const created = await api.createMySqlUser(setupUser.username, rand('mu'), 'InitialPass123!');
    let account = created.data.user;
    let pendingCleanup = true;
    expect(account).toBeTruthy();

    try {
      expect((await api.getMySqlUser(setupUser.username, account)).data).toBeTruthy();

      const renamed = await api.renameMySqlUser(setupUser.username, account, rand('ren'));
      expect(renamed.data.user, 'rename returned no new name').toBeTruthy();
      account = renamed.data.user;

      await api.changeMySqlUserPassword(setupUser.username, account, UPDATED_DB_PASSWORD);
      expect((await api.getMySqlUser(setupUser.username, account)).data).toBeTruthy();

      await api.deleteMySqlUser(setupUser.username, account);
      pendingCleanup = false;

      let listedUsers: string[] = [];
      await waitForCondition(
        async () => {
          listedUsers = (await api.listMySqlUsers(setupUser.username)).data.map(
            (entry) => entry.user
          );
          return !listedUsers.includes(account);
        },
        {
          timeout: settings.timing.propagationDelay,
          interval: 500,
          message: `MySQL user ${account} still listed after delete`,
          describeLast: () =>
            `listed users: ${redactAndTruncate(listedUsers.join(', ') || '(none)', 300)}`,
        }
      );
    } finally {
      if (pendingCleanup) {
        await mysqlFactory.deleteMySqlUser(setupUser.username, account);
      }
    }
  });

  const unknownUserOperations = [
    ['reading', 'get'],
    ['renaming', 'rename'],
    ['changing the password of', 'password'],
    ['deleting', 'delete'],
  ] as const;

  for (const [label, operation] of unknownUserOperations) {
    test(`${label} an unknown user returns 404`, async ({ authedRequest, setupUser }) => {
      const base = `projects/${setupUser.username}/mysql/users/nonexistent_user_xyz`;

      const response = await (async () => {
        switch (operation) {
          case 'get':
            return authedRequest.get(base);
          case 'rename':
            return authedRequest.put(`${base}/rename`, { data: { name: 'renamed_xyz' } });
          case 'password':
            return authedRequest.put(`${base}/change-password`, {
              data: { password: 'NewPass123!' },
            });
          case 'delete':
            return authedRequest.delete(base);
        }
      })();

      await expectStatus(response, 404);
    });
  }

  const invalidUsernames = [
    ['empty', ''],
    ['with a leading space', ' user'],
    ['containing a dollar sign', 'user$'],
    ['uppercase', 'UPPER'],
    ['over the length limit', 'toolongusername_exceeds'],
  ] as const;

  for (const [label, name] of invalidUsernames) {
    // As with database names, 201 is tolerated until the engine validates these.
    test(`a MySQL username ${label} does not corrupt the account`, async ({
      authedRequest,
      setupUser,
    }) => {
      const response = await authedRequest.post(`projects/${setupUser.username}/mysql/users`, {
        data: { name, password: 'StrongPass123!' },
      });
      expectOneOf(response.status(), [201, 400, 422]);

      if (response.status() === 201) {
        const created = createdMySqlName(await response.json()) ?? name;
        await authedRequest.delete(
          `projects/${setupUser.username}/mysql/users/${encodeURIComponent(created)}`
        );
      }
    });
  }

  const weakPasswords = ['short', 'password', '12345678', 'aaaaaaa1'];

  for (const password of weakPasswords) {
    test(`a weak password ${JSON.stringify(password)} does not corrupt the account`, async ({
      authedRequest,
      setupUser,
    }) => {
      const name = rand('wp');
      const response = await authedRequest.post(`projects/${setupUser.username}/mysql/users`, {
        data: { name, password },
      });
      expectOneOf(response.status(), [201, 400, 422]);

      if (response.status() === 201) {
        const created = createdMySqlName(await response.json()) ?? name;
        await authedRequest.delete(
          `projects/${setupUser.username}/mysql/users/${encodeURIComponent(created)}`
        );
      }
    });
  }
});

test.describe('MySQL rename and password validation', () => {
  const invalidRenames = ['', ' ', 'user with space', 'user$'];

  for (const name of invalidRenames) {
    test(`renaming to ${JSON.stringify(name)} is refused`, async ({
      api,
      authedRequest,
      mysqlFactory,
      setupUser,
    }) => {
      const account = (await api.createMySqlUser(setupUser.username, rand('rv'), 'RenameValid123!'))
        .data.user;

      try {
        const response = await authedRequest.put(
          `projects/${setupUser.username}/mysql/users/${account}/rename`,
          { data: { name } }
        );
        expectOneOf(response.status(), [400, 422]);
      } finally {
        await mysqlFactory.deleteMySqlUser(setupUser.username, account);
      }
    });
  }

  const invalidPasswords = ['', 'short', '       '];

  for (const password of invalidPasswords) {
    test(`changing the password to ${JSON.stringify(password)} is refused`, async ({
      api,
      authedRequest,
      mysqlFactory,
      setupUser,
    }) => {
      const account = (
        await api.createMySqlUser(setupUser.username, rand('cp'), 'InitialValid123!')
      ).data.user;

      try {
        const response = await authedRequest.put(
          `projects/${setupUser.username}/mysql/users/${account}/change-password`,
          { data: { password } }
        );
        expectOneOf(response.status(), [400, 422]);
      } finally {
        await mysqlFactory.deleteMySqlUser(setupUser.username, account);
      }
    });
  }

  test('an unknown privilege name is refused', async ({
    api,
    authedRequest,
    mysqlFactory,
    setupUser,
  }) => {
    const database = await mysqlFactory.createDatabase(setupUser.username, rand('pv'));
    const account = (await api.createMySqlUser(setupUser.username, rand('pv'), 'PrivValid123!'))
      .data.user;

    try {
      const response = await authedRequest.put(
        `projects/${setupUser.username}/mysql/privileges/${account}/${database}`,
        { data: { privileges: 'INVALID_PRIV' } }
      );
      expectOneOf(response.status(), [400, 422]);
    } finally {
      await mysqlFactory.deleteMySqlUser(setupUser.username, account);
      await mysqlFactory.deleteDatabase(setupUser.username, database);
    }
  });
});
