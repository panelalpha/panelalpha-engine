import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import type { HostExec } from '@/helpers/host-exec';
import { expectStatus } from '@/helpers/expect-status';
import { rand } from '@/helpers/random';
import { stagingUserPayload, waitUntilStagingActive } from '@/helpers/staging-helpers';
import { taskIdFromBody, waitForTask } from '@/helpers/task-helpers';
import { skipUnless } from '@/helpers/test-helpers';

const ENGINE_COMPOSE_FILE = '/opt/panelalpha/shared-hosting/docker-compose.yml';

/** A single-quoted argument for the shell that runs inside sites-db. */
function sqlQuote(sql: string): string {
  return `'${sql.replace(/'/g, `'\\''`)}'`;
}

/** Runs one SQL statement in the shared sites-db and returns its tab-separated rows. */
async function runSql(hostExec: HostExec, sql: string): Promise<string> {
  const result = await hostExec.run('docker', [
    'compose',
    '-f',
    ENGINE_COMPOSE_FILE,
    'exec',
    '-T',
    'sites-db',
    'sh',
    '-c',
    `mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B -e ${sqlQuote(sql)}`,
  ]);
  expect(result.exitCode, result.stderr || result.stdout).toBe(0);
  return result.stdout.trim();
}

test.describe('selective push: table selection', () => {
  test('a push with tables changes only the selected table', async ({
    api,
    hostExec,
    userFactory,
  }) => {
    test.setTimeout(Timeouts.deploy);
    skipUnless(hostExec, 'the sites-db service is not reachable from this runner.');

    const live = await userFactory.createSimpleUser();
    const requestedDatabase = rand('wp');
    let stagingUsername: string | undefined;

    try {
      const database = await api.createMySqlDatabase(live.username, requestedDatabase);
      const liveDatabase = database.data.database;

      await runSql(
        hostExec,
        `CREATE TABLE \`${liveDatabase}\`.wp_posts (id INT PRIMARY KEY, title VARCHAR(64));`
      );
      await runSql(hostExec, `INSERT INTO \`${liveDatabase}\`.wp_posts VALUES (1, 'live');`);
      await runSql(
        hostExec,
        `CREATE TABLE \`${liveDatabase}\`.wp_options (id INT PRIMARY KEY, value VARCHAR(64));`
      );
      await runSql(
        hostExec,
        `INSERT INTO \`${liveDatabase}\`.wp_options VALUES (1, 'live-option');`
      );

      const staging = await api.createStagingRaw(live.username, {});
      await expectStatus(staging, 202);
      stagingUsername = stagingUserPayload(staging.body).username;
      expect(stagingUsername, 'the staging response names the destination').toBeTruthy();
      await waitUntilStagingActive(api, stagingUsername!);

      const stagingDatabase = `${stagingUsername}_${requestedDatabase}`;

      await runSql(
        hostExec,
        `UPDATE \`${stagingDatabase}\`.wp_posts SET title = 'staged' WHERE id = 1;`
      );
      await runSql(
        hostExec,
        `UPDATE \`${stagingDatabase}\`.wp_options SET value = 'staged-option' WHERE id = 1;`
      );

      const pushed = await api.pushProjectRaw(stagingUsername!, live.username, {
        tables: [{ database: stagingDatabase, name: 'wp_posts' }],
      });
      await expectStatus(pushed, 202);
      const taskId = taskIdFromBody(pushed.body);
      expect(taskId, 'the push answers with a task id').toBeGreaterThan(0);
      const task = await waitForTask(api, taskId!);
      expect(task.status, JSON.stringify(task.details)).toBe('completed');

      expect(
        await runSql(hostExec, `SELECT title FROM \`${liveDatabase}\`.wp_posts WHERE id = 1;`),
        'the selected table was replaced'
      ).toBe('staged');
      expect(
        await runSql(hostExec, `SELECT value FROM \`${liveDatabase}\`.wp_options WHERE id = 1;`),
        'the table that was not selected keeps its rows'
      ).toBe('live-option');
    } finally {
      if (stagingUsername) {
        await api.deleteUserSafe(stagingUsername);
      }
      await userFactory.deleteUser(live.username);
    }
  });

  test('a table that is not on the source and a disabled databases flag are refused', async ({
    api,
    hostExec,
    userFactory,
  }) => {
    test.setTimeout(Timeouts.deploy);
    skipUnless(hostExec, 'the sites-db service is not reachable from this runner.');

    const live = await userFactory.createSimpleUser();
    const requestedDatabase = rand('wp');
    let stagingUsername: string | undefined;

    try {
      const database = await api.createMySqlDatabase(live.username, requestedDatabase);
      const liveDatabase = database.data.database;
      await runSql(
        hostExec,
        `CREATE TABLE \`${liveDatabase}\`.wp_posts (id INT PRIMARY KEY, title VARCHAR(64));`
      );

      const staging = await api.createStagingRaw(live.username, {});
      await expectStatus(staging, 202);
      stagingUsername = stagingUserPayload(staging.body).username;
      expect(stagingUsername, 'the staging response names the destination').toBeTruthy();
      await waitUntilStagingActive(api, stagingUsername!);

      const stagingDatabase = `${stagingUsername}_${requestedDatabase}`;

      const missing = await api.pushProjectRaw(stagingUsername!, live.username, {
        tables: [{ database: stagingDatabase, name: 'wp_absent' }],
      });
      await expectStatus(missing, 422);
      expect(JSON.stringify(missing.body), 'the refusal names the missing table').toContain(
        'table_not_found'
      );

      const disabled = await api.pushProjectRaw(stagingUsername!, live.username, {
        databases: false,
        tables: [{ database: stagingDatabase, name: 'wp_posts' }],
      });
      await expectStatus(disabled, 422);
      expect(JSON.stringify(disabled.body), 'the refusal names the flag').toContain(
        'tables_not_applicable'
      );
    } finally {
      if (stagingUsername) {
        await api.deleteUserSafe(stagingUsername);
      }
      await userFactory.deleteUser(live.username);
    }
  });

  test('push/diff reports a changed row and an added column', async ({
    api,
    hostExec,
    userFactory,
  }) => {
    test.setTimeout(Timeouts.deploy);
    skipUnless(hostExec, 'the sites-db service is not reachable from this runner.');

    const live = await userFactory.createSimpleUser();
    const requestedDatabase = rand('wp');
    let stagingUsername: string | undefined;

    try {
      const database = await api.createMySqlDatabase(live.username, requestedDatabase);
      const liveDatabase = database.data.database;
      await runSql(
        hostExec,
        `CREATE TABLE \`${liveDatabase}\`.wp_posts (id INT PRIMARY KEY, title VARCHAR(64));`
      );
      await runSql(hostExec, `INSERT INTO \`${liveDatabase}\`.wp_posts VALUES (1, 'live');`);
      await runSql(
        hostExec,
        `CREATE TABLE \`${liveDatabase}\`.wp_options (id INT PRIMARY KEY, value VARCHAR(64));`
      );
      await runSql(
        hostExec,
        `INSERT INTO \`${liveDatabase}\`.wp_options VALUES (1, 'live-option');`
      );

      const staging = await api.createStagingRaw(live.username, {});
      await expectStatus(staging, 202);
      stagingUsername = stagingUserPayload(staging.body).username;
      await waitUntilStagingActive(api, stagingUsername!);
      const stagingDatabase = `${stagingUsername}_${requestedDatabase}`;

      await runSql(
        hostExec,
        `ALTER TABLE \`${stagingDatabase}\`.wp_posts ADD COLUMN slug VARCHAR(64);`
      );
      await runSql(
        hostExec,
        `UPDATE \`${stagingDatabase}\`.wp_options SET value = 'staged-option' WHERE id = 1;`
      );

      const diff = await api.pushDiff(stagingUsername!, live.username);
      expect(
        diff.data.tables_structure_diff,
        'the added column shows as a structure difference'
      ).toContainEqual({ database: stagingDatabase, name: 'wp_posts' });
      expect(
        diff.data.tables_data_diff,
        'the changed row shows as a data difference'
      ).toContainEqual({ database: stagingDatabase, name: 'wp_options' });
    } finally {
      if (stagingUsername) {
        await api.deleteUserSafe(stagingUsername);
      }
      await userFactory.deleteUser(live.username);
    }
  });
});
