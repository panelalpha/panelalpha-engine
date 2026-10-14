import { Readable } from 'node:stream';
import SftpClient from 'ssh2-sftp-client';
import { expect, test } from '@/fixtures/test-options';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { removeFileIfPresent } from '@/helpers/file-path-helpers';
import { withFtpClient } from '@/helpers/ftp-helpers';
import { rand } from '@/helpers/random';
import { delay } from '@/helpers/retry';
import { requireEngineConnectHost } from '@/helpers/engine-host';
import { getUserUidGid, parseUidGid } from '@/helpers/uid-helpers';

/**
 * FTP and SFTP run as their own daemons outside the user's container, so they
 * are the two routes most likely to write files under the wrong UID — which
 * leaves the site owner unable to touch what they just uploaded.
 */
test.describe('ownership of transferred files', () => {
  test(
    'a file uploaded over FTP belongs to the account',
    {
      tag: ['@security'],
    },
    async ({ api, ftpFactory, settings, setupUser }) => {
      const uid = await getUserUidGid(api, setupUser.username);
      const account = await ftpFactory.createFtpAccount(setupUser.username, setupUser.domain);
      const host = await requireEngineConnectHost(api);

      const fileName = `${rand('ftp-uid')}.txt`;
      const remotePath = `${setupUser.domain}/public_html/${fileName}`;

      const cleanup = new CleanupSteps();
      try {
        await test.step('upload a file over FTP', async () => {
          await withFtpClient(async (client) => {
            await client.access({
              host,
              port: settings.ports.ftp,
              user: account.user,
              password: account.password,
              secure: false,
            });

            await client.uploadFrom(Readable.from(['FTP test content']), remotePath);
          });
        });

        await test.step('check the file ownership', async () => {
          const ownership = parseUidGid(await api.getFileStat(setupUser.username, remotePath));
          expect(ownership.uid, `the FTP upload is owned by uid ${ownership.uid}`).toBe(uid.uid);
          expect(ownership.gid, `the FTP upload has gid ${ownership.gid}`).toBe(uid.gid);
        });
      } catch (error) {
        cleanup.bodyFailed(error);
        throw error;
      } finally {
        await cleanup.run(`remove ${remotePath}`, () =>
          removeFileIfPresent(api, setupUser.username, remotePath)
        );
        await cleanup.run('delete the FTP account', () =>
          ftpFactory.deleteFtpAccount(setupUser.username, account.user)
        );
        await cleanup.finish();
      }
    }
  );

  test(
    'a file uploaded over SFTP belongs to the account',
    {
      tag: ['@security'],
    },
    async ({ api, ftpFactory, settings, setupUser }) => {
      const uid = await getUserUidGid(api, setupUser.username);
      const account = await ftpFactory.createSftpAccountWithPassword(
        setupUser.username,
        `${setupUser.username}_${rand('sftp')}`
      );

      const fileName = `${rand('sftp-uid')}.txt`;
      const remotePath = `/${setupUser.domain}/public_html/${fileName}`;
      const client = new SftpClient();

      const cleanup = new CleanupSteps();
      try {
        await test.step('connect over SFTP', async () => {
          await delay(settings.timing.propagationDelay);
          await client.connect({
            host: await requireEngineConnectHost(api),
            port: settings.ports.sftp,
            username: account.username,
            password: account.password,
          });
        });

        await test.step('upload a file over SFTP', async () => {
          await client.put(Buffer.from('SFTP test content'), remotePath);
        });

        await test.step('check the file ownership', async () => {
          const ownership = parseUidGid(
            await api.getFileStat(setupUser.username, `${setupUser.domain}/public_html/${fileName}`)
          );
          expect(ownership.uid, `the SFTP upload is owned by uid ${ownership.uid}`).toBe(uid.uid);
          expect(ownership.gid, `the SFTP upload has gid ${ownership.gid}`).toBe(uid.gid);
        });
      } catch (error) {
        cleanup.bodyFailed(error);
        throw error;
      } finally {
        // Closing a client that may never have connected; a failure here says nothing about the test.
        await client.end().catch(() => undefined);
        await cleanup.run(`remove ${setupUser.domain}/public_html/${fileName}`, () =>
          removeFileIfPresent(
            api,
            setupUser.username,
            `${setupUser.domain}/public_html/${fileName}`
          )
        );
        await cleanup.run('delete the SFTP account', () =>
          ftpFactory.deleteSftpAccount(setupUser.username, account.username)
        );
        await cleanup.finish();
      }
    }
  );
});
