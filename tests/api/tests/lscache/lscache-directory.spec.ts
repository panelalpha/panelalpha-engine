import { expect, test } from '@/fixtures/test-options';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { lscacheUnavailableReason } from '@/helpers/lscache-helpers';

/**
 * LiteSpeed writes its page cache into the site's own wp-content tree. If the
 * webserver creates those files as root, the site owner can no longer clear
 * their own cache — and neither can the panel.
 */
test.describe('LiteSpeed cache directory', () => {
  test.beforeEach(async ({ api }) => {
    const reason = await lscacheUnavailableReason(api);
    test.skip(Boolean(reason), reason ?? '');
  });

  const cachePath = (wpPath: string) => `${wpPath}/wp-content/cache/litespeed`;

  test('the cache directory belongs to the site owner, not root', async ({ api, setupUser }) => {
    const path = cachePath(setupUser.wpPath);

    await api.createDirectory(setupUser.username, path, true);

    const cleanup = new CleanupSteps();
    try {
      const stat = await api.getFileStat(setupUser.username, path);

      expect(stat.user_id).toBeDefined();
      expect(stat.group_id).toBeDefined();
      expect(
        stat.user_id,
        'the cache directory is owned by root, so the site owner cannot clear it'
      ).not.toBe('0');
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run('remove the cache directory', () =>
        api.removeFile(setupUser.username, path, true)
      );
      await cleanup.finish();
    }
  });

  test('the cache directory can be deleted through the API', async ({ api, setupUser }) => {
    const path = cachePath(setupUser.wpPath);

    await api.createDirectory(setupUser.username, `${path}/test`, true);
    await api.putFileContents(setupUser.username, `${path}/test/test.cache`, 'test cache content');

    await api.removeFile(setupUser.username, path, true);

    expect((await api.fileExists(setupUser.username, path)).exists).toBe(false);
  });
});
