import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { privilegesAsText } from '@/helpers/mysql-helpers';
import { rand, randomDomain, randomUsername } from '@/helpers/random';

test.describe('user clone', () => {
  test('cloning a user creates a distinct active account', async ({
    api,
    userFactory,
    settings,
  }) => {
    const source = await userFactory.createSimpleUser();
    const newUsername = randomUsername(12);
    const newDomain = randomDomain(settings.requireDomain());
    let cloneUsername: string | undefined;

    try {
      const cloned = await test.step('clone the user', async () => {
        const response = await api.cloneUser(source.username, {
          new_username: newUsername,
          domain: newDomain,
        });
        cloneUsername = response.data.username;
        return response;
      });

      await test.step('check the clone response', async () => {
        expect(cloned.data.username, 'clone username').toBe(newUsername);
        expect(cloned.data.domain, 'clone main domain').toBe(newDomain);
        expect(cloned.data.status, 'clone status').toBe('active');
        expect(cloned.data.id, 'the clone has its own id').not.toBe(
          (await api.getUser(source.username)).data.id
        );
      });

      await test.step('read the clone back', async () => {
        const fetched = await api.getUser(newUsername);
        expect(fetched.data.domain, 'stored clone domain').toBe(newDomain);
      });
    } finally {
      if (cloneUsername) {
        await api.deleteUserSafe(cloneUsername);
      }
      await userFactory.deleteUser(source.username);
    }
  });

  test('cloning a classic project copies its MySQL database and user', async ({
    api,
    userFactory,
    settings,
  }) => {
    const source = await userFactory.createSimpleUser();
    const requestedDatabase = rand('wp');
    const requestedMysqlUser = rand('dbu');
    const newUsername = randomUsername(12);
    let cloneUsername: string | undefined;

    try {
      const database = await api.createMySqlDatabase(source.username, requestedDatabase);
      const sourceDatabase = database.data.database;
      const mysqlUser = await api.createMySqlUser(
        source.username,
        requestedMysqlUser,
        'CopyPass123!'
      );
      const sourceMysqlUser = mysqlUser.data.user;
      await api.updateMySqlPrivileges(source.username, sourceMysqlUser, sourceDatabase, [
        'SELECT',
        'INSERT',
        'UPDATE',
        'DELETE',
      ]);

      // Clone is synchronous: the databases exist when the call returns.
      const cloned = await api.cloneUser(source.username, {
        new_username: newUsername,
        domain: randomDomain(settings.requireDomain()),
      });
      cloneUsername = cloned.data.username;

      const expectedDatabase = `${cloneUsername}_${requestedDatabase}`;
      const expectedMysqlUser = `${cloneUsername}_${requestedMysqlUser}`;

      const databases = await api.listMySqlDatabases(cloneUsername);
      expect(
        databases.data.map((entry) => entry.database),
        'the clone owns the mapped database'
      ).toContain(expectedDatabase);

      const users = await api.listMySqlUsers(cloneUsername);
      expect(
        users.data.map((entry) => entry.user),
        'the clone owns the mapped MySQL user'
      ).toContain(expectedMysqlUser);

      const privileges = await api.getMySqlPrivileges(
        cloneUsername,
        expectedMysqlUser,
        expectedDatabase
      );
      expect(privilegesAsText(privileges.data), 'the copied user keeps its grants').toContain(
        'SELECT'
      );
    } finally {
      if (cloneUsername) {
        await api.deleteUserSafe(cloneUsername);
      }
      await userFactory.deleteUser(source.username);
    }
  });

  test('cloning with copy_databases false copies no database', async ({
    api,
    userFactory,
    settings,
  }) => {
    const source = await userFactory.createSimpleUser();
    const newUsername = randomUsername(12);
    let cloneUsername: string | undefined;

    try {
      await api.createMySqlDatabase(source.username, rand('wp'));

      const cloned = await api.cloneUser(source.username, {
        new_username: newUsername,
        domain: randomDomain(settings.requireDomain()),
        copy_databases: false,
      });
      cloneUsername = cloned.data.username;

      const databases = await api.listMySqlDatabases(cloneUsername);
      expect(databases.data, 'the clone has no databases').toHaveLength(0);
    } finally {
      if (cloneUsername) {
        await api.deleteUserSafe(cloneUsername);
      }
      await userFactory.deleteUser(source.username);
    }
  });

  test('cloning an unknown user returns 404', async ({ api }) => {
    const response = await api.cloneUserRaw('nosuchuser999');
    await expectStatus(response, 404);
  });

  test('an invalid clone username is rejected', async ({ api, userFactory, settings }) => {
    const source = await userFactory.createSimpleUser();

    try {
      const response = await api.cloneUserRaw(source.username, {
        new_username: '1bad',
        domain: randomDomain(settings.requireDomain()),
      });
      expect([400, 422], 'an invalid clone username is rejected with 400 or 422').toContain(
        response.status
      );
    } finally {
      await userFactory.deleteUser(source.username);
    }
  });
});
