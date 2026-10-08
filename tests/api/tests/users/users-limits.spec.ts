import { expect, test } from '@/fixtures/test-options';
import { redactAndTruncate } from '@/helpers/redact';
import { rand, randomDomain } from '@/helpers/random';
import { waitForCondition } from '@/helpers/retry';

test.describe('resource limits', () => {
  test('every limit field round-trips through the config', async ({ api, userFactory }) => {
    const user = await userFactory.createUser();
    const limits = {
      disk_space_limit: 1_073_741_824,
      memory_limit: 512,
      cpu_limit: 1.5,
      device_read_bps: 10_485_760,
      device_write_bps: 10_485_760,
      bandwidth_limit: 1_073_741_824,
      mysql_databases_limit: 10,
      ftp_accounts_limit: 5,
      sftp_accounts_limit: 3,
      addon_domains_limit: 2,
      subdomains_limit: 5,
      inodes_limit: 500_000,
    };

    await api.updateUser(user.username, limits);

    const { data } = await api.getUser(user.username);
    const config = data.config ?? data.details ?? {};
    expect(config).toMatchObject(limits);
  });
});

test.describe('rebuild', () => {
  test('keeps FTP, SFTP, MySQL and addon domains intact', async ({
    api,
    userFactory,
    settings,
  }) => {
    const user = await userFactory.createSimpleUser();
    const ftpUser = rand('ftp');
    const sftpUsername = `${user.username}_${rand('sftp')}`;
    const addonDomain = randomDomain(settings.requireDomain());

    const database = await test.step('create the FTP, SFTP and MySQL resources', async () => {
      await api.createFtpAccount(user.username, {
        user: ftpUser,
        domain: user.domain,
        password: 'FtpRebuild123!',
      });
      await api.createSftpAccount(user.username, {
        username: sftpUsername,
        auth_method: 'password',
        password: 'SftpRebuild123!',
      });
      return (await api.createMySqlDatabase(user.username, rand('db'))).data.database;
    });

    await test.step('create the addon domain', async () => {
      // The container needs its webserver up before it will accept an addon domain.
      let lastError = 'not attempted yet';
      await waitForCondition(
        async () => {
          try {
            await api.createDomain(user.username, { domain: addonDomain, type: 'addon' });
            return true;
          } catch (error) {
            lastError = `createDomain failed: ${redactAndTruncate(error instanceof Error ? error.message : String(error), 300)}`;
            return false;
          }
        },
        {
          timeout: settings.timing.propagationDelay + 60_000,
          interval: 3_000,
          message: `addon domain ${addonDomain} could not be created`,
          describeLast: () => lastError,
        }
      );
    });

    await test.step('rebuild the project', async () => {
      await api.rebuildUser(user.username);
    });

    await test.step('check the resources survived the rebuild', async () => {
      const [ftpAccounts, sftpAccounts, databases, domains] = await Promise.all([
        api.listFtpAccounts(user.username),
        api.listSftpAccounts(user.username),
        api.listMySqlDatabases(user.username),
        api.listUserDomains(user.username),
      ]);

      expect(
        ftpAccounts.data.map((account) => account.user),
        'the FTP account is still listed'
      ).toContain(`${ftpUser}@${user.domain}`);
      expect(
        sftpAccounts.data.map((account) => account.username),
        'the SFTP account is still listed'
      ).toContain(sftpUsername);
      expect(
        databases.data.map((db) => db.database),
        'the MySQL database is still listed'
      ).toContain(database);
      expect(
        domains.data.map((domain) => domain.domain),
        'the addon domain is still listed'
      ).toContain(addonDomain);
    });
  });
});
