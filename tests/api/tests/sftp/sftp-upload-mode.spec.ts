import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import SftpClient from 'ssh2-sftp-client';
import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import { requireEngineConnectHost } from '@/helpers/engine-host';
import { expectStatus } from '@/helpers/expect-status';
import { rand } from '@/helpers/random';
import { registerSecret } from '@/helpers/secret-registry';
import { readPage } from '@/helpers/site-page';
import { waitForSftpLogin } from '@/helpers/sftp-helpers';

const pollTimeout = (settings: { timing: { propagationDelay: number } }) =>
  Math.max(settings.timing.propagationDelay, 15_000);

/** An upload into the document root lands 0644 and the site serves it, whatever umask the container has. */
test.describe('an SFTP upload', () => {
  test('is readable by the site', async ({
    anonymousRequest,
    api,
    ftpFactory,
    settings,
    userFactory,
  }) => {
    test.setTimeout(Timeouts.deploy);

    const user = await userFactory.createSimpleUser();
    const account = await ftpFactory.createSftpAccountWithPassword(user.username);
    const fileName = `${rand('sftp-')}.html`;
    const marker = rand('sftp-upload-');
    const host = await requireEngineConnectHost(api);
    const credentials = {
      host,
      port: settings.ports.sftp,
      username: account.username,
      password: account.password,
    };
    const scratch = await mkdtemp(path.join(tmpdir(), 'sftp-upload-'));
    const localFile = path.join(scratch, fileName);
    await writeFile(localFile, `<!doctype html><html><body><p>${marker}</p></body></html>`);

    try {
      await waitForSftpLogin(credentials, { timeout: pollTimeout(settings) });

      const client = new SftpClient();
      registerSecret(account.password);
      try {
        await client.connect(credentials);
        await client.fastPut(localFile, `/${user.domain}/public_html/${fileName}`);
      } finally {
        await client.end().catch(() => undefined);
      }

      const stat = await api.getFileStat(user.username, `/${user.domain}/public_html/${fileName}`);
      expect(stat.mode, 'the mode the upload landed with').toBe('0644');

      const page = await readPage(anonymousRequest, `https://${user.domain}/${fileName}`);
      await expectStatus(page, 200, `GET /${fileName}`);
      expect(page.body, 'the uploaded page the site serves').toContain(marker);
    } finally {
      await rm(scratch, { recursive: true, force: true });
      await ftpFactory.deleteSftpAccount(user.username, account.username);
    }
  });
});
