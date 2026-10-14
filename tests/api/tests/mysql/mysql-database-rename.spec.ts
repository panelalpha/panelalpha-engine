import { expect, test } from '@/fixtures/test-options';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { expectStatus } from '@/helpers/expect-status';
import { privilegesAsText } from '@/helpers/mysql-helpers';
import { rand } from '@/helpers/random';

/**
 * PUT /projects/{username}/mysql/databases/{dbname}/rename answers 202 with a
 * task. The task moves the data and the grants, rewrites the stored row and
 * drops the old database.
 */
test.describe('MySQL database rename', () => {
  test('a database is renamed and its grants follow', async ({
    api,
    authedRequest,
    mysqlFactory,
    setupUser,
  }, testInfo) => {
    const database = await mysqlFactory.createDatabase(setupUser.username, rand('rn'));
    const account = (await api.createMySqlUser(setupUser.username, rand('ru'), 'RenamePass123!'))
      .data.user;
    const cleanup = new CleanupSteps(testInfo);
    let renamed: string | undefined = database;

    try {
      await api.updateMySqlPrivileges(setupUser.username, account, database, ['SELECT', 'INSERT']);

      const task = await api.renameMySqlDatabase(setupUser.username, database, rand('rnnew'));
      const details = task.details as { action?: string; from?: string; to?: string };
      expect(details.action, 'the task says which operation it ran').toBe('mysql_database_rename');
      expect(details.from, 'the task names the database it moved').toBe(database);
      expect(details.to, 'the task names the new database').toMatch(
        new RegExp(`^${setupUser.username}_`)
      );

      const newName = details.to!;
      renamed = newName;

      await test.step('the new name is stored and the old one is gone', async () => {
        expect((await api.getMySqlDatabase(setupUser.username, newName)).data.database).toBe(
          newName
        );
        expect(
          (await api.listMySqlDatabases(setupUser.username)).data.map((entry) => entry.database)
        ).toContain(newName);
        await expectStatus(
          await authedRequest.get(
            `projects/${setupUser.username}/mysql/databases/${encodeURIComponent(database)}`
          ),
          404
        );
      });

      await test.step('the grant moved with the data', async () => {
        const granted = await api.getMySqlPrivileges(setupUser.username, account, newName);
        expect(privilegesAsText(granted)).toContain('SELECT');
        await expectStatus(
          await authedRequest.get(
            `projects/${setupUser.username}/mysql/privileges/${account}/${encodeURIComponent(database)}`
          ),
          404
        );
      });
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run('delete the renamed database', () =>
        mysqlFactory.deleteDatabase(setupUser.username, renamed!)
      );
      await cleanup.run('delete the MySQL user the test created', () =>
        mysqlFactory.deleteMySqlUser(setupUser.username, account)
      );
      await cleanup.finish();
    }
  });

  test('renaming onto an existing database is refused', async ({
    authedRequest,
    mysqlFactory,
    setupUser,
  }) => {
    const database = await mysqlFactory.createDatabase(setupUser.username, rand('rt'));
    const occupant = rand('ro');

    try {
      await mysqlFactory.createDatabase(setupUser.username, occupant);

      const response = await authedRequest.put(
        `projects/${setupUser.username}/mysql/databases/${encodeURIComponent(database)}/rename`,
        { data: { name: occupant } }
      );

      await expectStatus(response, 422, await response.text());
      expect(((await response.json()) as { problems?: unknown[] }).problems).toContainEqual(
        expect.objectContaining({ field: 'name', code: 'name_taken' })
      );
    } finally {
      await mysqlFactory.deleteDatabase(setupUser.username, database);
      await mysqlFactory.deleteDatabase(setupUser.username, `${setupUser.username}_${occupant}`);
    }
  });

  test('a name over the column length is refused', async ({
    authedRequest,
    mysqlFactory,
    setupUser,
  }) => {
    const database = await mysqlFactory.createDatabase(setupUser.username, rand('rl'));

    try {
      const response = await authedRequest.put(
        `projects/${setupUser.username}/mysql/databases/${encodeURIComponent(database)}/rename`,
        { data: { name: 'x'.repeat(64) } }
      );

      await expectStatus(response, 422, await response.text());
      expect(((await response.json()) as { problems?: unknown[] }).problems).toContainEqual(
        expect.objectContaining({ field: 'name', code: 'name_too_long' })
      );
    } finally {
      await mysqlFactory.deleteDatabase(setupUser.username, database);
    }
  });

  test('the dind application database cannot be renamed into', async ({
    authedRequest,
    mysqlFactory,
    setupDindUser,
  }) => {
    const database = await mysqlFactory.createDatabase(setupDindUser.username, rand('rd'));

    try {
      const response = await authedRequest.put(
        `projects/${setupDindUser.username}/mysql/databases/${encodeURIComponent(database)}/rename`,
        { data: { name: 'app' } }
      );

      await expectStatus(response, 422, await response.text());
      expect(((await response.json()) as { problems?: unknown[] }).problems).toContainEqual(
        expect.objectContaining({ field: 'name', code: 'managed_database' })
      );
    } finally {
      await mysqlFactory.deleteDatabase(setupDindUser.username, database);
    }
  });
});
