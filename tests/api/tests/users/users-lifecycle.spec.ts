import { expect, test } from '@/fixtures/test-options';
import type { UserCredentials } from '@/types/user.types';
import { waitForCondition } from '@/helpers/retry';

/**
 * The full lifecycle of a single user, in order: create it, read it back, change
 * it, rebuild it, suspend it, and finally delete it.
 *
 * `serial` because every step operates on the user the previous one left behind,
 * and creating a user costs a container start — running each step against its own
 * fresh user would multiply the runtime for no extra coverage.
 */
test.describe.configure({ mode: 'serial' });

test.describe('user lifecycle', () => {
  let user: UserCredentials;

  test('create a user', { tag: ['@smoke'] }, async ({ api, userFactory, settings }) => {
    // The chain deletes this user in its last step, so opt out of per-test cleanup.
    user = await userFactory.createUser({ autoCleanup: false });

    let seen: string | undefined;

    await waitForCondition(
      async () => {
        seen = (await api.getUser(user.username)).data?.username;
        return seen === user.username;
      },
      {
        timeout: settings.timing.propagationDelay,
        interval: 500,
        message: `User ${user.username} did not become readable`,
        describeLast: () => `GET user returned username: ${seen ?? '(none)'}`,
      }
    );
  });

  test('read the user back', async ({ api, userAssertions }) => {
    const { data } = await api.getUser(user.username);
    await userAssertions.verifyUserStructure(data, user.username);
  });

  test('update the email address', async ({ api, userAssertions }) => {
    const email = `${user.username}@mail.test`;
    await api.updateUser(user.username, { email });
    await userAssertions.verifyUserEmail(user.username, email);
  });

  test('update IO limits and PHP settings', async ({ api }) => {
    const deviceReadBps = 1_048_576;
    const deviceWriteBps = 2_097_152;

    await api.updateUser(user.username, {
      device_read_bps: deviceReadBps,
      device_write_bps: deviceWriteBps,
      php_fpm_pool_settings: [
        'pm = dynamic',
        'pm.max_children = 7',
        'pm.start_servers = 2',
        'pm.min_spare_servers = 1',
        'pm.max_spare_servers = 3',
        'pm.max_requests = 100',
      ].join('\n'),
      lsphp_settings: ['PHP_LSAPI_CHILDREN=12', 'PHP_LSAPI_MAX_REQUESTS=200'].join('\n'),
    });

    const { config } = (await api.getUser(user.username)).data;
    expect(config.device_read_bps).toBe(deviceReadBps);
    expect(config.device_write_bps).toBe(deviceWriteBps);
    expect(config.php_fpm_pool_settings?.pm).toBe('dynamic');
    expect(config.php_fpm_pool_settings?.['pm.max_children']).toBe('7');
    expect(config.lsphp_settings?.PHP_LSAPI_CHILDREN).toBe('12');
  });

  test('rebuild the user', async ({ api, userAssertions }) => {
    await api.rebuildUser(user.username);
    await userAssertions.verifyUserExists(user.username);
  });

  test('suspend and unsuspend', async ({ api, userAssertions }) => {
    await api.suspendUser(user.username);
    await userAssertions.verifyUserStatus(user.username, 'suspended');

    await api.unsuspendUser(user.username);
    await userAssertions.verifyUserStatus(user.username, 'active');
  });

  test('report usage', async ({ api }) => {
    expect(await api.getUserUsage(user.username)).toHaveProperty('storage');
  });

  test('delete the user', async ({ userFactory, userAssertions }) => {
    await userFactory.deleteUser(user.username);
    await userAssertions.verifyUserNotExists(user.username);
  });
});
