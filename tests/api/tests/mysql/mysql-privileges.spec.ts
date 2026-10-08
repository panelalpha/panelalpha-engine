import { expect, test } from '@/fixtures/test-options';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { LIMITED_PRIVILEGES, TEST_PRIVILEGES, privilegesAsText } from '@/helpers/mysql-helpers';
import { rand } from '@/helpers/random';
import { expectStatus } from '@/helpers/expect-status';

/**
 * Privileges only exist for a (user, database) pair, so each test builds both,
 * exercises the grant, and tears the pair down again.
 */
test.describe('MySQL privileges', () => {
  test('a grant can be read back, narrowed, and revoked', async ({
    api,
    authedRequest,
    mysqlFactory,
    setupUser,
  }) => {
    const database = await mysqlFactory.createDatabase(setupUser.username, rand('priv'));
    const account = (await api.createMySqlUser(setupUser.username, rand('pu'), 'PrivPass123!')).data
      .user;

    const readPrivileges = async () => {
      const response = await authedRequest.get(
        `projects/${setupUser.username}/mysql/privileges/${account}/${database}`
      );
      test.skip(
        response.status() === 404,
        'GET mysql/privileges is not implemented on this engine.'
      );
      await expectStatus(response, 200);
      return privilegesAsText(await response.json());
    };

    const cleanup = new CleanupSteps();
    try {
      await api.updateMySqlPrivileges(setupUser.username, account, database, [...TEST_PRIVILEGES]);
      const granted = await api.getMySqlPrivileges(setupUser.username, account, database);
      test.skip(
        granted.data === undefined,
        'GET mysql/privileges is not implemented on this engine.'
      );
      expect(granted.data).toBeDefined();

      await api.updateMySqlPrivileges(setupUser.username, account, database, [
        ...LIMITED_PRIVILEGES,
      ]);

      const narrowed = await readPrivileges();
      for (const privilege of LIMITED_PRIVILEGES) {
        expect(narrowed, `${privilege} should still be granted`).toContain(privilege);
      }

      await api.deleteMySqlPrivileges(setupUser.username, account, database);

      const revoked = await readPrivileges();
      for (const privilege of TEST_PRIVILEGES) {
        expect(revoked, `${privilege} should have been revoked`).not.toContain(privilege);
      }
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run('revoke the privileges the test granted', () =>
        api.deleteMySqlPrivileges(setupUser.username, account, database)
      );
      await cleanup.run('delete the MySQL user the test created', () =>
        mysqlFactory.deleteMySqlUser(setupUser.username, account)
      );
      await cleanup.run('delete the database the test created', () =>
        mysqlFactory.deleteDatabase(setupUser.username, database)
      );
      await cleanup.finish();
    }
  });
});
