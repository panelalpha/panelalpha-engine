import { expect, test } from '@/fixtures/test-options';
import type { EngineApi } from '@/clients/engine-api';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { removeFileIfPresent } from '@/helpers/file-path-helpers';
import type { SetupTestData } from '@/types/user.types';
import { getUserUidGid, parseUidGid, type UidGidInfo } from '@/helpers/uid-helpers';
import { wpCliUnavailableReason, wpPath } from '@/helpers/wpcli-helpers';

/**
 * Everything a site owner creates has to end up owned by their own UID, whatever
 * route created it. A file written as root — by the webserver, by cron, by a
 * transfer daemon — is one the owner can neither edit nor delete, and it is how
 * a container escape starts.
 */
test.describe('file ownership', () => {
  let uid: UidGidInfo;

  test.beforeEach(async ({ api, setupUser }) => {
    uid = await getUserUidGid(api, setupUser.username);

    expect(uid.uid, 'the user has UID 0, which means it runs as root').toBeGreaterThan(0);
    expect(uid.gid, 'the user has GID 0, which means it runs as root').toBeGreaterThan(0);
  });

  /** Asserts a path belongs to the account, naming the route that created it. */
  const expectOwnedByUser = async (
    api: EngineApi,
    setupUser: SetupTestData,
    path: string,
    route: string
  ) => {
    const ownership = parseUidGid(await api.getFileStat(setupUser.username, path));

    expect(ownership.uid, `${path} (created via ${route}) is owned by uid ${ownership.uid}`).toBe(
      uid.uid
    );
    expect(ownership.gid, `${path} (created via ${route}) has gid ${ownership.gid}`).toBe(uid.gid);
  };

  const apiRoutes = [
    [
      'put-contents',
      async (api: EngineApi, user: SetupTestData, base: string) => {
        const path = `${base}/api_put_${Date.now()}.txt`;
        await api.putFileContents(user.username, path, 'Test content');
        return path;
      },
    ],
    [
      'mkdir',
      async (api: EngineApi, user: SetupTestData, base: string) => {
        const path = `${base}/api_dir_${Date.now()}`;
        await api.createDirectory(user.username, path);
        return path;
      },
    ],
    [
      'copy',
      async (api: EngineApi, user: SetupTestData, base: string) => {
        const source = `${base}/api_copy_source_${Date.now()}.txt`;
        const path = `${base}/api_copied_${Date.now()}.txt`;
        await api.putFileContents(user.username, source, 'Source content');
        await api.copyFile(user.username, source, path);
        const cleanup = new CleanupSteps();
        await cleanup.run(`remove ${source}`, () =>
          removeFileIfPresent(api, user.username, source)
        );
        await cleanup.finish();
        return path;
      },
    ],
    [
      'move',
      async (api: EngineApi, user: SetupTestData, base: string) => {
        const source = `${base}/api_move_source_${Date.now()}.txt`;
        const path = `${base}/api_moved_${Date.now()}.txt`;
        await api.putFileContents(user.username, source, 'Source content');
        await api.moveFile(user.username, source, path);
        return path;
      },
    ],
  ] as const;

  for (const [route, create] of apiRoutes) {
    test(
      `a file created via ${route} belongs to the account`,
      {
        tag: ['@security'],
      },
      async ({ api, setupUser }) => {
        const base = `${setupUser.domain}/public_html`;
        const path = await create(api, setupUser, base);

        const cleanup = new CleanupSteps();
        try {
          await expectOwnedByUser(api, setupUser, path, route);
        } catch (error) {
          cleanup.bodyFailed(error);
          throw error;
        } finally {
          await cleanup.run(`remove ${path}`, () =>
            removeFileIfPresent(api, setupUser.username, path, true)
          );
          await cleanup.finish();
        }
      }
    );
  }

  test(
    'PHP runs as the account and its output belongs to it',
    {
      tag: ['@security'],
    },
    async ({ api, anonymousRequest, setupUser }) => {
      const base = `${setupUser.domain}/public_html`;
      const script = `${base}/uid_check.php`;

      await test.step('write the PHP script', async () => {
        await api.putFileContents(
          setupUser.username,
          script,
          "<?php echo json_encode(['uid' => posix_getuid(), 'gid' => posix_getgid()]);"
        );
      });

      const cleanup = new CleanupSteps();
      try {
        await test.step('run the script and check the PHP user', async () => {
          const response = await anonymousRequest.get(`https://${setupUser.domain}/uid_check.php`, {
            ignoreHTTPSErrors: true,
          });
          expect(response.ok(), 'the PHP script answered successfully').toBe(true);

          const reported = (await response.json()) as { uid: string; gid: string };
          expect(Number.parseInt(reported.uid, 10), 'PHP does not run as the account').toBe(
            uid.uid
          );
          expect(Number.parseInt(reported.gid, 10), 'PHP does not run with the account gid').toBe(
            uid.gid
          );
        });

        await test.step('check the script file ownership', async () => {
          await expectOwnedByUser(api, setupUser, script, 'the API');
        });
      } catch (error) {
        cleanup.bodyFailed(error);
        throw error;
      } finally {
        await cleanup.run(`remove ${script}`, () =>
          removeFileIfPresent(api, setupUser.username, script)
        );
        await cleanup.finish();
      }
    }
  );

  test(
    'files a PHP script writes belong to the account',
    {
      tag: ['@security'],
    },
    async ({ api, anonymousRequest, setupUser }) => {
      const base = `${setupUser.domain}/public_html`;
      const script = `${base}/create_files.php`;
      const createdFile = `${base}/php_created_file.txt`;
      const createdDir = `${base}/php_created_dir`;

      await test.step('write the PHP script', async () => {
        await api.putFileContents(
          setupUser.username,
          script,
          "<?php file_put_contents('php_created_file.txt', 'Created by PHP'); @mkdir('php_created_dir'); echo 'Files created';"
        );
      });

      const cleanup = new CleanupSteps();
      try {
        await test.step('run the script', async () => {
          const response = await anonymousRequest.get(
            `https://${setupUser.domain}/create_files.php`,
            { ignoreHTTPSErrors: true }
          );
          expect(response.ok(), 'the PHP script answered successfully').toBe(true);
        });

        await test.step('check the ownership of the created file and directory', async () => {
          await expectOwnedByUser(api, setupUser, createdFile, 'a PHP script');
          await expectOwnedByUser(api, setupUser, createdDir, 'a PHP script');
        });
      } catch (error) {
        cleanup.bodyFailed(error);
        throw error;
      } finally {
        for (const path of [script, createdFile, createdDir]) {
          await cleanup.run(`remove ${path}`, () =>
            removeFileIfPresent(api, setupUser.username, path, true)
          );
        }
        await cleanup.finish();
      }
    }
  );

  test(
    'files WP-CLI writes belong to the account',
    {
      tag: ['@security'],
    },
    async ({ api, setupUser }) => {
      const reason = await wpCliUnavailableReason(api, setupUser);
      test.skip(Boolean(reason), reason ?? '');

      const slug = 'hello-dolly';
      const pluginDir = `${setupUser.domain}/public_html/wp-content/plugins/${slug}`;

      await test.step('install the plugin with WP-CLI', async () => {
        const installed = await api.executeWpCliCommand(setupUser.username, [
          'plugin',
          'install',
          slug,
          wpPath(setupUser),
        ]);
        expect(installed.exit_code, installed.stderr).toBe(0);
      });

      const cleanup = new CleanupSteps();
      try {
        await test.step('check the plugin directory ownership', async () => {
          await expectOwnedByUser(api, setupUser, pluginDir, 'WP-CLI plugin install');
        });
      } catch (error) {
        cleanup.bodyFailed(error);
        throw error;
      } finally {
        await cleanup.run('delete the WP-CLI test plugin', () =>
          api.executeWpCliCommand(setupUser.username, ['plugin', 'delete', slug, wpPath(setupUser)])
        );
        await cleanup.finish();
      }
    }
  );
});
