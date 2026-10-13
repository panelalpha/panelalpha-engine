import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { expectStatus } from '@/helpers/expect-status';
import type { HostExec } from '@/helpers/host-exec';
import { rand } from '@/helpers/random';
import { stagingUserPayload, waitUntilStagingActive } from '@/helpers/staging-helpers';
import { skipUnless } from '@/helpers/test-helpers';
import type { EngineApi } from '@/clients/engine-api';

const ENGINE_COMPOSE_FILE = '/opt/panelalpha/shared-hosting/docker-compose.yml';
const HOST_EXEC_SKIP = 'pae-artisan is not reachable from this runner. The test reads sites-db.';

const OWNED_ROUTINE = 'pa_owned';
const ROOTED_ROUTINE = 'pa_rooted';
const ROOT_DEFINER = 'root@localhost';

/** A single-quoted argument for the shell that runs inside sites-db. */
function sqlQuote(sql: string): string {
  return `'${sql.replace(/'/g, `'\\''`)}'`;
}

/** Runs one SQL statement in the shared sites-db as root and returns its tab-separated rows. */
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

/** Routine name => DEFINER (`user@host`) for every routine in one database. */
async function routineDefiners(hostExec: HostExec, database: string): Promise<Map<string, string>> {
  const rows = await runSql(
    hostExec,
    `SELECT ROUTINE_NAME, DEFINER FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '${database}';`
  );
  const definers = new Map<string, string>();
  for (const line of rows.split('\n').filter((row) => row !== '')) {
    const [name, definer] = line.split('\t');
    definers.set(name, definer);
  }
  return definers;
}

interface SeededDatabase {
  database: string;
  /** The project's own MySQL user that owns one routine, as `user@host`. */
  ownerDefiner: string;
  /** The project MySQL user's name, without the host. */
  account: string;
}

/**
 * A project database holding two procedures, both created by root: one whose
 * DEFINER is a MySQL user of the project, and one whose DEFINER is root.
 */
async function seedRoutines(
  api: EngineApi,
  hostExec: HostExec,
  username: string
): Promise<SeededDatabase> {
  const database = (await api.createMySqlDatabase(username, rand('df'))).data.database;
  const account = (await api.createMySqlUser(username, rand('du'), 'DefinerPass123!')).data.user;
  await api.updateMySqlPrivileges(username, account, database, ['SELECT', 'INSERT']);

  const host = await runSql(hostExec, `SELECT Host FROM mysql.user WHERE User = '${account}';`);
  expect(host, `sites-db has the project user ${account}`).not.toBe('');

  await runSql(
    hostExec,
    `CREATE DEFINER=\`${account}\`@\`${host}\` PROCEDURE \`${database}\`.\`${OWNED_ROUTINE}\`() SELECT 1;`
  );
  await runSql(
    hostExec,
    `CREATE DEFINER=\`root\`@\`localhost\` PROCEDURE \`${database}\`.\`${ROOTED_ROUTINE}\`() SELECT 1;`
  );

  const ownerDefiner = `${account}@${host}`;
  const seeded = await routineDefiners(hostExec, database);
  expect(seeded.get(OWNED_ROUTINE), 'the seeded routine is the project user’s').toBe(ownerDefiner);
  expect(seeded.get(ROOTED_ROUTINE), 'the seeded routine is root’s').toBe(ROOT_DEFINER);

  return { database, ownerDefiner, account };
}

function expectNoRootDefiner(definers: Map<string, string>, where: string): void {
  const rootOwned = [...definers].filter(([, definer]) => definer.startsWith('root@'));
  expect(rootOwned, `no routine in ${where} runs as root`).toEqual([]);
}

/**
 * A copy of a database imports its dump as root. Every routine it recreates
 * keeps a project definer: a project MySQL user stays that user (or the user
 * it maps to on the destination), and any other definer, root included,
 * becomes an account under the destination's prefix instead of root.
 */
test.describe('MySQL copies keep routine definers out of root', () => {
  test('a rename keeps the project user as definer and gives root’s routine none', async ({
    api,
    hostExec,
    userFactory,
  }) => {
    test.setTimeout(Timeouts.deploy);
    skipUnless(hostExec, HOST_EXEC_SKIP);

    const project = await userFactory.createSimpleUser();
    const seeded = await seedRoutines(api, hostExec, project.username);

    const task = await api.renameMySqlDatabase(project.username, seeded.database, rand('dfnew'));
    const renamed = (task.details as { to?: string }).to;
    expect(renamed, 'the rename task names the new database').toBeTruthy();

    const definers = await routineDefiners(hostExec, renamed!);
    expect([...definers.keys()].sort(), 'both routines were copied').toEqual(
      [OWNED_ROUTINE, ROOTED_ROUTINE].sort()
    );
    expectNoRootDefiner(definers, renamed!);
    expect(definers.get(OWNED_ROUTINE), 'the project user keeps its routine').toBe(
      seeded.ownerDefiner
    );
    expect(
      definers.get(ROOTED_ROUTINE),
      'root’s routine is handed to an account under the project prefix'
    ).toMatch(new RegExp(`^${project.username}_`));
  });

  test('a staging copy gives no routine a root definer', async ({
    api,
    hostExec,
    userFactory,
  }, testInfo) => {
    test.setTimeout(Timeouts.deploy);
    skipUnless(hostExec, HOST_EXEC_SKIP);

    const live = await userFactory.createSimpleUser();
    const seeded = await seedRoutines(api, hostExec, live.username);
    const cleanup = new CleanupSteps(testInfo);
    let stagingUsername: string | undefined;

    try {
      const staging = await api.createStagingRaw(live.username, {});
      await expectStatus(staging, 202);
      stagingUsername = stagingUserPayload(staging.body).username;
      expect(stagingUsername, 'the staging response names the destination').toBeTruthy();
      await waitUntilStagingActive(api, stagingUsername!);

      const livePrefix = `${live.username}_`;
      const stagingDatabase = `${stagingUsername}_${seeded.database.slice(livePrefix.length)}`;
      const definers = await routineDefiners(hostExec, stagingDatabase);
      expect([...definers.keys()].sort(), 'both routines were copied to staging').toEqual(
        [OWNED_ROUTINE, ROOTED_ROUTINE].sort()
      );
      expectNoRootDefiner(definers, stagingDatabase);

      const stagingAccount = `${stagingUsername}_${seeded.account.slice(livePrefix.length)}`;
      expect(
        definers.get(OWNED_ROUTINE),
        'the project user’s routine belongs to the staging copy of that user'
      ).toMatch(new RegExp(`^${stagingAccount}@`));
      expect(
        definers.get(ROOTED_ROUTINE),
        'root’s routine is handed to an account under the staging prefix'
      ).toMatch(new RegExp(`^${stagingUsername}_`));
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      if (stagingUsername) {
        await cleanup.run('delete the staging project', () => api.deleteUserSafe(stagingUsername!));
      }
      await cleanup.finish();
    }
  });
});
