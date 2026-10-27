import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { expectStatus } from '@/helpers/expect-status';
import { privilegesAsText } from '@/helpers/mysql-helpers';
import { rand, randomUsername } from '@/helpers/random';
import { stagingUserPayload, waitUntilStagingActive } from '@/helpers/staging-helpers';

test.describe('user staging', () => {
  test('staging a PHP user is accepted or refused as unsupported', async ({ api, userFactory }) => {
    const source = await userFactory.createSimpleUser();
    let stagingUsername: string | undefined;
    try {
      const response = await api.createStagingRaw(source.username, {});
      expectOneOf(response.status, [202, 422]);
      if (response.status === 422) {
        expect(JSON.stringify(response.body)).toMatch(/not supported/i);
        return;
      }
      stagingUsername = stagingUserPayload(response.body as unknown).username;
      expect(stagingUsername).toBeTruthy();
    } finally {
      if (stagingUsername) {
        await api.deleteUserSafe(stagingUsername);
      }
      await userFactory.deleteUser(source.username);
    }
  });

  test('staging a classic project copies its MySQL database and user', async ({
    api,
    userFactory,
  }) => {
    const source = await userFactory.createSimpleUser();
    const requestedDatabase = rand('wp');
    const requestedMysqlUser = rand('dbu');
    let stagingUsername: string | undefined;

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

      const response = await api.createStagingRaw(source.username, {});
      await expectStatus(response, 202);
      stagingUsername = stagingUserPayload(response.body as unknown).username;
      expect(stagingUsername, 'staging username').toBeTruthy();
      await waitUntilStagingActive(api, stagingUsername!);

      const expectedDatabase = `${stagingUsername}_${requestedDatabase}`;
      const expectedMysqlUser = `${stagingUsername}_${requestedMysqlUser}`;

      const databases = await api.listMySqlDatabases(stagingUsername!);
      expect(
        databases.data.map((entry) => entry.database),
        'the staging copy owns the mapped database'
      ).toContain(expectedDatabase);

      const users = await api.listMySqlUsers(stagingUsername!);
      expect(
        users.data.map((entry) => entry.user),
        'the staging copy owns the mapped MySQL user'
      ).toContain(expectedMysqlUser);

      const privileges = await api.getMySqlPrivileges(
        stagingUsername!,
        expectedMysqlUser,
        expectedDatabase
      );
      expect(privilegesAsText(privileges.data), 'the copied user keeps its grants').toContain(
        'SELECT'
      );

      const staging = await api.getUser(stagingUsername!);
      const stagingDomain = staging.data.domain;
      const domain = await api.getDomain(stagingUsername!, stagingDomain);
      expect(
        domain.data.details?.document_root,
        'the copied site is served from its own domain dir'
      ).toBe(`/${stagingDomain}/public_html`);
    } finally {
      if (stagingUsername) {
        await api.deleteUserSafe(stagingUsername);
      }
      await userFactory.deleteUser(source.username);
    }
  });

  test('a classic WordPress is not staged or cloned without its databases', async ({
    api,
    userFactory,
  }) => {
    const source = await userFactory.createSimpleUser();
    const newUsername = randomUsername(12);

    try {
      await api.putFileContents(
        source.username,
        `${source.domain}/public_html/wp-config.php`,
        '<?php\n'
      );

      const refusals = {
        staging: await api.createStagingRaw(source.username, {
          new_username: newUsername,
          copy_databases: false,
        }),
        clone: await api.cloneUserRaw(source.username, {
          new_username: newUsername,
          copy_databases: false,
        }),
      };
      for (const [action, response] of Object.entries(refusals)) {
        await expectStatus(response, 422);
        expect(JSON.stringify(response.body), `the ${action} refusal names the code`).toContain(
          'wordpress_copy_needs_databases'
        );
      }

      await expectStatus(await api.getUserRaw(newUsername), 404);
    } finally {
      await api.deleteUserSafe(newUsername);
      await userFactory.deleteUser(source.username);
    }
  });

  test('staging unknown user returns 404', async ({ api }) => {
    const response = await api.createStagingRaw('nosuchuser999', {});
    await expectStatus(response, 404);
  });

  test('push without target is 422', async ({ api, userFactory }) => {
    const source = await userFactory.createSimpleUser();
    try {
      const response = await api.pushProjectRaw(source.username, '');
      expect([400, 422]).toContain(response.status);
    } finally {
      await userFactory.deleteUser(source.username);
    }
  });

  test('push with both files and databases disabled is refused', async ({ api, userFactory }) => {
    const source = await userFactory.createSimpleUser();
    try {
      const response = await api.pushProjectRaw(source.username, source.username, {
        files: false,
        databases: false,
      });
      await expectStatus(response, 422);
      expect(JSON.stringify(response.body), 'the refusal names nothing_to_push').toContain(
        'nothing_to_push'
      );
    } finally {
      await userFactory.deleteUser(source.username);
    }
  });
});
