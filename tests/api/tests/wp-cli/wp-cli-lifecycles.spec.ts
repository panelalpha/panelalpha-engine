import { expect, test } from '@/fixtures/test-options';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { expectOneOf } from '@/helpers/expect-one-of';
import { rand } from '@/helpers/random';
import { wpCliUnavailableReason, wpPath } from '@/helpers/wpcli-helpers';

/**
 * Managing users, plugins, themes and posts through WP-CLI, end to end.
 *
 * Each lifecycle is one test: every step needs the id the previous one produced,
 * and the last step is the cleanup, so splitting them would leave debris behind
 * on any failure.
 */
test.describe('WP-CLI lifecycles', () => {
  test.beforeEach(async ({ api, setupUser }) => {
    const reason = await wpCliUnavailableReason(api, setupUser);
    test.skip(Boolean(reason), reason ?? '');
  });

  test('a WordPress user is created, re-roled and deleted', async ({ api, setupUser }) => {
    const run = (...args: string[]) =>
      api.executeWpCliCommand(setupUser.username, [...args, wpPath(setupUser)]);

    const login = rand('testuser');
    const email = `${login}@test.local`;

    const created = await run('user', 'create', login, email, '--role=editor', '--porcelain');
    expect(created.exit_code, created.stderr).toBe(0);

    const userId = Number.parseInt(created.stdout.trim(), 10);
    expect(userId).toBeGreaterThan(0);

    const cleanup = new CleanupSteps();
    try {
      const initial = await run('user', 'get', String(userId), '--format=json');
      expect(initial.exit_code, initial.stderr).toBe(0);
      expect(JSON.parse(initial.stdout)).toMatchObject({
        user_login: login,
        user_email: email,
      });

      const reRoled = await run('user', 'set-role', String(userId), 'author');
      expect(reRoled.exit_code, reRoled.stderr).toBe(0);

      const updated = await run('user', 'get', String(userId), '--format=json');
      expect((JSON.parse(updated.stdout) as { roles: string[] }).roles).toContain('author');

      const deleted = await run('user', 'delete', String(userId), '--yes');
      expect(deleted.exit_code, deleted.stderr).toBe(0);

      const gone = await run('user', 'get', String(userId), '--format=json');
      expect(gone.exit_code, 'the deleted user is still readable').not.toBe(0);
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run('delete the user the test created', () =>
        run('user', 'delete', String(userId), '--yes')
      );
      await cleanup.finish();
    }
  });

  test('a plugin is installed, activated, deactivated and deleted', async ({ api, setupUser }) => {
    const run = (...args: string[]) =>
      api.executeWpCliCommand(setupUser.username, [...args, wpPath(setupUser)]);

    const slug = 'hello-dolly';
    const isInstalled = async () => {
      const list = await run('plugin', 'list', '--format=json');
      expect(list.exit_code, list.stderr).toBe(0);
      return (JSON.parse(list.stdout) as { name: string; status: string }[]).find(
        (plugin) => plugin.name === slug
      );
    };

    // Exit code 1 means "already installed", which is fine to build on.
    const installed = await run('plugin', 'install', slug);
    expectOneOf(installed.exit_code, [0, 1]);

    const cleanup = new CleanupSteps();
    try {
      expect(await isInstalled(), `${slug} is not in the plugin list after install`).toBeTruthy();

      const activated = await run('plugin', 'activate', slug);
      expect(activated.exit_code, activated.stderr).toBe(0);
      expect((await isInstalled())?.status).toBe('active');

      const deactivated = await run('plugin', 'deactivate', slug);
      expect(deactivated.exit_code, deactivated.stderr).toBe(0);
      expect((await isInstalled())?.status).toBe('inactive');

      const deleted = await run('plugin', 'delete', slug);
      expect(deleted.exit_code, deleted.stderr).toBe(0);
      expect(await isInstalled(), `${slug} survived deletion`).toBeUndefined();
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run('delete the plugin the test installed', () =>
        run('plugin', 'delete', slug)
      );
      await cleanup.finish();
    }
  });

  test('a theme is installed, activated and deleted', async ({ api, setupUser }) => {
    const run = (...args: string[]) =>
      api.executeWpCliCommand(setupUser.username, [...args, wpPath(setupUser)]);

    const slug = 'astra';
    const themes = async () => {
      const list = await run('theme', 'list', '--format=json');
      expect(list.exit_code, list.stderr).toBe(0);
      return JSON.parse(list.stdout) as { name: string; status: string }[];
    };

    const installed = await run('theme', 'install', slug);
    expectOneOf(installed.exit_code, [0, 1]);

    const original = (await themes()).find((theme) => theme.status === 'active')?.name;

    const cleanup = new CleanupSteps();
    try {
      expect((await themes()).some((theme) => theme.name === slug)).toBe(true);

      const activated = await run('theme', 'activate', slug);
      expect(activated.exit_code, activated.stderr).toBe(0);
      expect((await themes()).find((theme) => theme.name === slug)?.status).toBe('active');

      // A theme cannot be deleted while it is the active one.
      if (original) {
        await run('theme', 'activate', original);
      }

      const deleted = await run('theme', 'delete', slug);
      expect(deleted.exit_code, deleted.stderr).toBe(0);
      expect((await themes()).some((theme) => theme.name === slug)).toBe(false);
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      if (original) {
        await cleanup.run(
          'activate the original theme again',
          () => run('theme', 'activate', original),
          {
            verify: async () =>
              (await themes()).find((theme) => theme.status === 'active')?.name === original,
            describe: () => `the active theme is not back to "${original}"`,
          }
        );
      }
      await cleanup.run('delete the theme the test installed', () => run('theme', 'delete', slug));
      await cleanup.finish();
    }
  });

  test('a post is created, updated and deleted', async ({ api, setupUser }) => {
    const run = (...args: string[]) =>
      api.executeWpCliCommand(setupUser.username, [...args, wpPath(setupUser)]);

    const title = rand('post');
    const created = await run(
      'post',
      'create',
      `--post_title=${title}`,
      '--post_status=publish',
      '--porcelain'
    );
    expect(created.exit_code, created.stderr).toBe(0);

    const postId = Number.parseInt(created.stdout.trim(), 10);
    expect(postId).toBeGreaterThan(0);

    const cleanup = new CleanupSteps();
    try {
      const updatedTitle = `${title}-updated`;
      const updated = await run('post', 'update', String(postId), `--post_title=${updatedTitle}`);
      expect(updated.exit_code, updated.stderr).toBe(0);

      const read = await run('post', 'get', String(postId), '--field=post_title');
      expect(read.exit_code, read.stderr).toBe(0);
      expect(read.stdout.trim()).toBe(updatedTitle);

      const deleted = await run('post', 'delete', String(postId), '--force');
      expect(deleted.exit_code, deleted.stderr).toBe(0);

      const gone = await run('post', 'get', String(postId), '--field=post_title');
      expect(gone.exit_code, 'the deleted post is still readable').not.toBe(0);
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run('delete the post the test created', () =>
        run('post', 'delete', String(postId), '--force')
      );
      await cleanup.finish();
    }
  });
});
