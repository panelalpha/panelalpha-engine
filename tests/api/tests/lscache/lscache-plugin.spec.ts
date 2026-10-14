import { expect, test } from '@/fixtures/test-options';
import { CleanupSteps } from '@/helpers/cleanup-step';
import {
  LSCACHE_PLUGIN,
  ensureLscachePluginActive,
  isLscachePluginActive,
  lscacheUnavailableReason,
} from '@/helpers/lscache-helpers';
import { wpPath } from '@/helpers/wpcli-helpers';

test.describe('LiteSpeed Cache plugin', () => {
  let wasActive: boolean;

  test.beforeEach(async ({ api, setupUser }) => {
    const reason = await lscacheUnavailableReason(api);
    test.skip(Boolean(reason), reason ?? '');

    wasActive = await isLscachePluginActive(api, setupUser.username, wpPath(setupUser));
  });

  test.afterEach(async ({ api, setupUser }) => {
    // beforeEach can skip before it reads the plugin state; there is nothing to restore then.
    if (wasActive === undefined) {
      return;
    }

    const path = wpPath(setupUser);
    const cleanup = new CleanupSteps();
    await cleanup.run(
      'restore the LiteSpeed Cache plugin state',
      () =>
        api.executeWpCliCommand(setupUser.username, [
          'plugin',
          wasActive ? 'activate' : 'deactivate',
          LSCACHE_PLUGIN,
          path,
        ]),
      {
        verify: async () =>
          (await isLscachePluginActive(api, setupUser.username, path)) === wasActive,
        describe: () => `the plugin is not back to ${wasActive ? 'active' : 'inactive'}`,
      }
    );
    await cleanup.finish();
  });

  test('the plugin installs and activates', async ({ api, setupUser }) => {
    const path = wpPath(setupUser);

    await ensureLscachePluginActive(api, setupUser.username, path);

    expect(await isLscachePluginActive(api, setupUser.username, path)).toBe(true);
  });

  test('the plugin deactivates', async ({ api, setupUser }) => {
    const path = wpPath(setupUser);

    await ensureLscachePluginActive(api, setupUser.username, path);
    await api.executeWpCliCommand(setupUser.username, [
      'plugin',
      'deactivate',
      LSCACHE_PLUGIN,
      path,
    ]);

    expect(await isLscachePluginActive(api, setupUser.username, path)).toBe(false);
  });
});
