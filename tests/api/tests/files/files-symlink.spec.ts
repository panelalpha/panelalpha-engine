import { expect, test } from '@/fixtures/test-options';
import SftpClient from 'ssh2-sftp-client';
import { expectOneOf } from '@/helpers/expect-one-of';
import { getDomainBasePath, removeFileIfPresent } from '@/helpers/file-path-helpers';
import { rand } from '@/helpers/random';
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
      await expect
        .poll(
          async () => {
            try {
              await sftp.connect({
                host,
                port: settings.ports.sftp,
                username: account.username,
                password: account.password,
              });
              return 'connected';
            } catch (error) {
              // The connection may never have opened, so closing it can fail without meaning anything.
              await sftp.end().catch(() => undefined);
              return error instanceof Error ? error.message : String(error);
            }
          },
          {
            timeout: settings.timing.propagationDelay,
            intervals: [500],
            message: 'SFTP account was not reachable',
          }
        )
        .toBe('connected');

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
      // The connection may never have opened, so closing it can fail without meaning anything.
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

test('a symlink is listed as a link, and listing through one out of the home is refused', async ({
  api,
  ftpFactory,
  settings,
  setupUser,
}) => {
  const dir = getDomainBasePath(setupUser.domain);
  const suffix = Date.now();
  const targetName = `symlink_target_${suffix}.txt`;
  const dirName = `symlink_dir_${suffix}`;
  const fileLinkName = `symlink_file_${suffix}`;
  const dirLinkName = `symlink_dir_link_${suffix}`;
  const danglingName = `symlink_dangling_${suffix}`;
  const escapeName = `symlink_escape_${suffix}`;
  const host = await requireEngineConnectHost(api);

  const account = await ftpFactory.createSftpAccountWithPassword(
    setupUser.username,
    `${setupUser.username}_${rand('sftp')}`
  );
  const sftp = new SftpClient();

  try {
    await expect
      .poll(
        async () => {
          try {
            await sftp.connect({
              host,
              port: settings.ports.sftp,
              username: account.username,
              password: account.password,
            });
            return 'connected';
          } catch (error) {
            await sftp.end().catch(() => undefined);
            return error instanceof Error ? error.message : String(error);
          }
        },
        {
          timeout: settings.timing.propagationDelay,
          intervals: [500],
          message: 'SFTP account was not reachable',
        }
      )
      .toBe('connected');

    await api.putFileContents(setupUser.username, `${dir}/${targetName}`, 'target');
    await api.createDirectory(setupUser.username, `${dir}/${dirName}`);

    const sftpBase = `/${setupUser.domain}/public_html`;
    const links = [
      [targetName, `${sftpBase}/${fileLinkName}`],
      [dirName, `${sftpBase}/${dirLinkName}`],
      ['nowhere', `${sftpBase}/${danglingName}`],
      ['/root', `${sftpBase}/${escapeName}`],
    ] as const;

    for (const [target, linkPath] of links) {
      const created = await createSymlink(sftp, target, linkPath);
      test.skip(!created, 'This SFTP server does not expose a symlink operation.');
    }

    const listing = await api.listFiles(setupUser.username, dir);
    const byName = new Map(listing.data.map((entry) => [entry.name, entry]));

    expect(byName.get(fileLinkName)?.type).toBe('link');
    expect(byName.get(fileLinkName)?.target).toBe(targetName);
    expect(byName.get(fileLinkName)?.target_type).toBe('file');

    expect(byName.get(dirLinkName)?.type).toBe('link');
    expect(byName.get(dirLinkName)?.target).toBe(dirName);
    expect(byName.get(dirLinkName)?.target_type).toBe('dir');

    expect(byName.get(danglingName)?.type).toBe('link');
    expect(byName.get(danglingName)?.target_type).toBe('missing');

    expect(byName.get(escapeName)?.type).toBe('link');

    const throughEscape = await api.listFilesRaw(setupUser.username, {
      path: `${dir}/${escapeName}`,
    });
    expectOneOf(throughEscape.status, [422], 'listing through a link out of the home was allowed');
    expect(
      (throughEscape.body as { problems?: { field: string; code: string }[] }).problems
    ).toContainEqual(expect.objectContaining({ field: 'path', code: 'outside_home' }));
  } finally {
    await sftp.end().catch(() => undefined);
    await ftpFactory.deleteSftpAccount(setupUser.username, account.username);
    for (const name of [fileLinkName, dirLinkName, danglingName, escapeName]) {
      await api.removeFile(setupUser.username, `${dir}/${name}`).catch(() => undefined);
    }
    for (const name of [targetName, dirName]) {
      await removeFileIfPresent(api, setupUser.username, `${dir}/${name}`, true);
    }
  }
});
