import { test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { rand } from '@/helpers/random';
import { expectStatus } from '@/helpers/expect-status';
import type { EngineFixtures } from '@/fixtures/test-options';

/**
 * Deleting something twice must not blow up. The first DELETE removes the
 * resource; the second has to answer 404 rather than 500 or, worse, succeed
 * again against something that no longer exists.
 */

const IDEMPOTENT_STATUSES = [200, 204, 404] as const;

interface DeletableResource {
  /** REST collection under `projects/<username>/`. */
  collection: string;
  /** Creates one and returns the id the DELETE route addresses it by. */
  create: (fixtures: EngineFixtures, user: { username: string; domain: string }) => Promise<string>;
}

const RESOURCES: Record<string, DeletableResource> = {
  'an addon domain': {
    collection: 'domains',
    create: ({ domainFactory }, user) =>
      domainFactory.createAddonDomain(user.username, user.domain),
  },
  'a MySQL database': {
    collection: 'mysql/databases',
    create: ({ mysqlFactory }, user) => mysqlFactory.createDatabase(user.username, rand('idem')),
  },
  'a MySQL user': {
    collection: 'mysql/users',
    create: async ({ api }, user) =>
      (await api.createMySqlUser(user.username, rand('idem'), 'Idempotent123!')).data.user,
  },
  'a cron job': {
    collection: 'cron-jobs',
    create: async ({ cronFactory }, user) =>
      (
        await cronFactory.createMinuteCron(
          user.username,
          `echo idempotent-${Date.now()} > /dev/null`
        )
      ).hash,
  },
  'an FTP account': {
    collection: 'ftp-accounts',
    create: async ({ ftpFactory }, user) =>
      (await ftpFactory.createFtpAccount(user.username, user.domain)).user,
  },
  'an SFTP account': {
    collection: 'sftp-accounts',
    create: async ({ ftpFactory }, user) =>
      (
        await ftpFactory.createSftpAccountWithPassword(
          user.username,
          `${user.username}_idem${Date.now()}`
        )
      ).username,
  },
};

test.describe('double DELETE', () => {
  for (const [label, resource] of Object.entries(RESOURCES)) {
    // Every fixture the RESOURCES callbacks reach for has to be named in this
    // signature: Playwright decides what to build by parsing the parameter
    // list, so destructuring inside the body would hand the callbacks an
    // object of undefineds.
    test(`deleting ${label} twice answers 404 the second time`, async ({
      api,
      authedRequest,
      userFactory,
      domainFactory,
      mysqlFactory,
      cronFactory,
      ftpFactory,
    }) => {
      const user = await test.step('create a project', () => userFactory.createSimpleUser());

      const fixtures = {
        api,
        authedRequest,
        userFactory,
        domainFactory,
        mysqlFactory,
        cronFactory,
        ftpFactory,
      } as unknown as EngineFixtures;
      const id = await test.step(`create ${label}`, () => resource.create(fixtures, user));
      const path = `projects/${user.username}/${resource.collection}/${encodeURIComponent(id)}`;

      await test.step('delete it the first time', async () => {
        const first = await authedRequest.delete(path);
        expectOneOf(first.status(), IDEMPOTENT_STATUSES, `first DELETE of ${label}`);
      });

      await test.step('delete it the second time', async () => {
        const second = await authedRequest.delete(path);
        await expectStatus(second, 404, `second DELETE of ${label} should report it is gone`);
      });
    });
  }
});
