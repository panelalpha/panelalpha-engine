import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { getDomainBasePath } from '@/helpers/file-path-helpers';
import { rand } from '@/helpers/random';

/**
 * One tenant must not be able to reach another's resources by naming them under
 * their own path. Every endpoint that takes both a username and a resource id is
 * a place where the engine could look up the resource without checking it
 * belongs to that user.
 */
const DENIED = [403, 404] as const;

test.describe('cross-user isolation', () => {
  test(
    'one user cannot reach another user resources',
    {
      tag: ['@security'],
    },
    async ({
      api,
      authedRequest,
      cronFactory,
      domainFactory,
      ftpFactory,
      mysqlFactory,
      userFactory,
    }) => {
      const [attacker, victim] = await Promise.all([
        userFactory.createSimpleUser(),
        userFactory.createSimpleUser(),
      ]);

      // The home root is root-owned; write where a hosting user can, and make sure the
      // file is really there, or every denial below proves nothing.
      const secretPath = `${getDomainBasePath(victim.domain)}/${rand('iso')}.txt`;
      await api.putFileContents(victim.username, secretPath, `isolation-secret-${Date.now()}`);
      expect((await api.fileExists(victim.username, secretPath)).exists).toBe(true);

      const [addonDomain, database, ftpAccount, cronJob] = await Promise.all([
        domainFactory.createAddonDomain(victim.username, victim.domain),
        mysqlFactory.createDatabase(victim.username, rand('iso')),
        ftpFactory.createFtpAccount(victim.username, victim.domain),
        cronFactory.createMinuteCron(victim.username, `echo isolation-${Date.now()} > /dev/null`),
      ]);

      /** Requests made under the attacker's namespace, naming the victim's resource. */
      const crossUserRequests = [
        [
          'the victim addon domain',
          () =>
            authedRequest.get(
              `projects/${attacker.username}/domains/${encodeURIComponent(addonDomain)}`
            ),
        ],
        [
          'the victim database',
          () =>
            authedRequest.get(
              `projects/${attacker.username}/mysql/databases/${encodeURIComponent(database)}`
            ),
        ],
        [
          'the victim FTP account',
          () =>
            authedRequest.get(
              `projects/${attacker.username}/ftp-accounts/${encodeURIComponent(ftpAccount.user)}`
            ),
        ],
        [
          'the victim cron job',
          () =>
            authedRequest.delete(
              `projects/${attacker.username}/cron-jobs/${encodeURIComponent(cronJob.hash)}`
            ),
        ],
        [
          'the victim file, by download',
          () =>
            authedRequest.get(
              `projects/${attacker.username}/files/download?path=${encodeURIComponent(secretPath)}`
            ),
        ],
      ] as const;

      for (const [label, send] of crossUserRequests) {
        await test.step(`reaching ${label} is denied`, async () => {
          expectOneOf((await send()).status(), DENIED, `${label} was reachable`);
        });
      }

      await test.step('probing for the victim file leaks nothing', async () => {
        const response = await authedRequest.get(
          `projects/${attacker.username}/files/exists?path=${encodeURIComponent(secretPath)}`
        );

        // A 200 is acceptable only if it reports the file as absent — the point is
        // that the attacker learns nothing about the victim's filesystem.
        if (response.status() === 200) {
          const body = (await response.json()) as { exists?: boolean };
          expect(body.exists, 'the existence probe confirmed another user file').not.toBe(true);
          return;
        }

        expectOneOf(response.status(), DENIED, 'files/exists');
      });

      await test.step('running WP-CLI against the victim home is denied', async () => {
        const response = await authedRequest.post(`projects/${attacker.username}/wp-cli/command`, {
          data: {
            args: [
              'eval',
              'echo 1',
              `--path=/home/${victim.username}${getDomainBasePath(victim.domain)}`,
            ],
          },
        });

        // Refusing the request outright, or accepting it and having WP-CLI fail to
        // read the other user's directory, are both containment.
        if (response.status() === 200) {
          const body = (await response.json()) as { exit_code?: number };
          expect(body.exit_code, 'WP-CLI ran successfully inside another user home').not.toBe(0);
          return;
        }

        expectOneOf(response.status(), [403, 404, 422]);
      });
    }
  );
});
