import { test } from '@/fixtures/test-options';
import SftpClient from 'ssh2-sftp-client';
import { expectOneOf } from '@/helpers/expect-one-of';
import { getDomainBasePath } from '@/helpers/file-path-helpers';
import { rand } from '@/helpers/random';
import { delay } from '@/helpers/retry';
import { requireEngineConnectHost } from '@/helpers/engine-host';

/**
 * A user can create symlinks over SFTP. If the file API then follows one when
 * writing, a user could point a link at `/root/…` and have the engine write
 * there as root. The write must be refused, not followed.
 */
test(
  'writing through a symlink that escapes the home directory is refused',
  { tag: ['@security'] },
  async ({ api, authedRequest, ftpFactory, settings, setupUser }) => {
    const linkName = `symlink_attack_${Date.now()}.txt`;
    const apiPath = `${getDomainBasePath(setupUser.domain)}/${linkName}`;
    const sftpPath = `/${setupUser.domain}/public_html/${linkName}`;
    const target = '/root/test_symlink_attack';
    const host = await requireEngineConnectHost(api);

    const account = await ftpFactory.createSftpAccountWithPassword(
      setupUser.username,
      `${setupUser.username}_${rand('sftp')}`
    );
    const sftp = new SftpClient();

    try {
      await delay(settings.timing.propagationDelay);
      await sftp.connect({
        host,
        port: settings.ports.sftp,
        username: account.username,
        password: account.password,
      });

      const created = await createSymlink(sftp, target, sftpPath);
      test.skip(!created, 'This SFTP server does not expose a symlink operation.');

      const response = await authedRequest.put(
        `projects/${setupUser.username}/files/put-contents`,
        {
          data: { path: apiPath, contents: 'malicious_content' },
        }
      );

      expectOneOf(
        response.status(),
        [400, 403, 422],
        'the file API followed a symlink out of the home directory'
      );
    } finally {
      await sftp.end().catch(() => undefined);
      await ftpFactory.deleteSftpAccount(setupUser.username, account.username);
      await authedRequest.delete(
        `projects/${setupUser.username}/files/remove?path=${encodeURIComponent(apiPath)}`
      );
    }
  }
);

/** Creates a symlink over SFTP; returns false when the server has no symlink support. */
async function createSymlink(
  client: SftpClient,
  target: string,
  linkPath: string
): Promise<boolean> {
  const wrapper = (client as unknown as { sftp?: { symlink?: unknown } }).sftp;
  if (typeof wrapper?.symlink !== 'function') {
    return false;
  }

  try {
    await new Promise<void>((resolve, reject) => {
      (wrapper.symlink as (t: string, p: string, cb: (e?: Error) => void) => void)(
        target,
        linkPath,
        (error) => (error ? reject(error) : resolve())
      );
    });
    return true;
  } catch {
    return false;
  }
}
