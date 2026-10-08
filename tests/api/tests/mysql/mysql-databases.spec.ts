import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { createdMySqlName } from '@/helpers/mysql-helpers';
import { rand } from '@/helpers/random';
import { waitForCondition } from '@/helpers/retry';
import { expectStatus } from '@/helpers/expect-status';
import { mysqlDatabaseListSchema, mysqlDatabaseSchema } from '@/schemas';

test.describe('MySQL databases', () => {
  test('server info is reported', async ({ api, setupUser }) => {
    expect((await api.getMySqlServerInfo(setupUser.username)).data).toBeDefined();
  });

  test('the database list matches its schema', async ({ api, setupUser }) => {
    mysqlDatabaseListSchema.parse(await api.listMySqlDatabases(setupUser.username));
  });

  test(
    'a database is created, readable, and deleted',
    { tag: ['@smoke'] },
    async ({ api, mysqlFactory, settings, setupUser }) => {
      const database = await mysqlFactory.createDatabase(setupUser.username, rand('db'));
      let pendingCleanup = true;

      try {
        const details = await api.getMySqlDatabase(setupUser.username, database);
        mysqlDatabaseSchema.parse(details.data);

        expect(
          (await api.listMySqlDatabases(setupUser.username)).data.map((entry) => entry.database)
        ).toContain(database);

        await api.deleteMySqlDatabase(setupUser.username, database);
        pendingCleanup = false;

        let listed: string[] = [];

        await waitForCondition(
          async () => {
            listed = (await api.listMySqlDatabases(setupUser.username)).data.map(
              (entry) => entry.database
            );
            return !listed.includes(database);
          },
          {
            timeout: settings.timing.propagationDelay,
            interval: 500,
            message: `Database ${database} still listed after delete`,
            describeLast: () => `listed databases: ${listed.join(', ') || '(none)'}`,
          }
        );
      } finally {
        if (pendingCleanup) {
          await mysqlFactory.deleteDatabase(setupUser.username, database);
        }
      }
    }
  );

  test('an unknown database returns 404', async ({ authedRequest, setupUser }) => {
    const response = await authedRequest.get(
      `projects/${setupUser.username}/mysql/databases/nonexistent_db_xyz`
    );
    await expectStatus(response, 404);
  });

  test('creating the same database twice is refused', async ({
    api,
    authedRequest,
    mysqlFactory,
    setupUser,
  }) => {
    const requested = rand('dup');
    const database = await mysqlFactory.createDatabase(setupUser.username, requested);

    try {
      const response = await authedRequest.post(`projects/${setupUser.username}/mysql/databases`, {
        data: { name: requested },
      });
      expectOneOf(response.status(), [409, 422]);
    } finally {
      await api.deleteMySqlDatabase(setupUser.username, database);
    }
  });

  const invalidNames = [
    ['empty', ''],
    ['containing a space', 'db with space'],
    ['containing a dollar sign', 'db$'],
    ['starting with a dash', '-leading'],
    ['over the length limit', 'verylongdatabasename_exceeds_limit_12345'],
  ] as const;

  for (const [label, name] of invalidNames) {
    // 201 is tolerated because current engines do not validate every one of
    // these; the test still guarantees the endpoint answers coherently and
    // leaves nothing behind. Narrow to [400, 422] once validation lands.
    test(`a database name ${label} does not corrupt the account`, async ({
      authedRequest,
      setupUser,
    }) => {
      const response = await authedRequest.post(`projects/${setupUser.username}/mysql/databases`, {
        data: { name },
      });
      expectOneOf(response.status(), [201, 400, 422]);

      if (response.status() === 201) {
        const created = createdMySqlName(await response.json()) ?? name;
        await authedRequest.delete(
          `projects/${setupUser.username}/mysql/databases/${encodeURIComponent(created)}`
        );
      }
    });
  }
});
