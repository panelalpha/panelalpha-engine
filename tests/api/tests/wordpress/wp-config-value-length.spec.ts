import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import { expectStatus } from '@/helpers/expect-status';
import { installWordPressSite } from '@/helpers/wordpress-site';

/** A wp-config value WP-CLI can hold is stored and read back, and more than 16384 bytes is refused. */
test.describe('wp-config values', () => {
  test('a value of 8192 bytes is stored and the config list still reads', async ({
    api,
    userFactory,
  }) => {
    test.setTimeout(Timeouts.setup);

    const user = await userFactory.createUser();
    await installWordPressSite(api, user);
    const constant = 'PA_LONG_VALUE';
    const value = 'v'.repeat(8192);

    try {
      await api.wp(user.username, 'PUT', `config/${constant}`, { value });
      expect(
        (await api.wp<{ value: string }>(user.username, 'GET', `config/${constant}`)).value,
        'the value WP-CLI stores'
      ).toBe(value);

      const entries = await api.wp<{ name: string }[]>(user.username, 'GET', 'config');
      expect(
        entries.map((entry) => entry.name),
        'the whole wp-config list'
      ).toEqual(expect.arrayContaining(['DB_NAME', 'DB_HOST', constant]));
    } finally {
      await api.wpRaw(user.username, 'DELETE', `config/${constant}`);
    }

    const tooLong = await api.wpRaw(user.username, 'PUT', 'config/PA_TOO_LONG', {
      value: 'x'.repeat(16385),
    });
    await expectStatus(tooLong, 422);
    expect(JSON.stringify(tooLong.body), 'the refusal names the limit').toContain('16384');

    const refused = await api.wpRaw(user.username, 'GET', 'config/PA_TOO_LONG');
    await expectStatus(refused, [404, 422]);
  });
});
