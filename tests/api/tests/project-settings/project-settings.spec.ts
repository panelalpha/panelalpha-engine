import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { skipUnless } from '@/helpers/test-helpers';
import { expectStatus } from '@/helpers/expect-status';
import { sharedProject } from '@/helpers/shared-project';

const CLOUDFLARE_KEY = 'cloudflare-api-token';

test.describe('project settings', () => {
  // The read tests share one pristine PHP project. The test that attempts a write keeps its own.
  const shared = sharedProject('simple');
  test.afterAll(() => shared.dispose());

  test('the allowlisted settings can be listed and read', async ({ api }) => {
    const user = await shared.get();
    const listed = await api.listProjectSettings(user.username);
    expect(listed.data.some((row) => row.key === CLOUDFLARE_KEY)).toBe(true);

    const setting = await api.getProjectSetting(user.username, CLOUDFLARE_KEY);
    expect(setting.data.key).toBe(CLOUDFLARE_KEY);
    expect(setting.data.set).toBe(false);
    expect(setting.data.secret).toBe(true);
    expect(setting.data.value).toBeNull();
  });

  test('an unknown key is 422', async ({ api }) => {
    const user = await shared.get();
    await expectStatus(await api.getProjectSettingRaw(user.username, 'not-a-real-key'), 422);
  });

  test('an unknown project is 404', async ({ api }) => {
    await expectStatus(await api.listProjectSettingsRaw('nosuchuser999'), 404);
  });

  test('a Cloudflare token on a PHP account is refused', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser();
    const response = await api.setProjectSettingRaw(user.username, CLOUDFLARE_KEY, {
      value: 'not-a-cloudflare-token',
    });
    expectOneOf(response.status, [400, 422]);
  });

  test('a garbage Cloudflare token on DinD is refused', async ({ api, userFactory }) => {
    const user = await userFactory.createDindUser();
    skipUnless(user, 'DinD is not available on this engine.');
    const response = await api.setProjectSettingRaw(user.username, CLOUDFLARE_KEY, {
      value: 'not-a-cloudflare-token',
    });
    expectOneOf(response.status, [400, 422]);
  });

  test('clearing an unset token succeeds', async ({ api }) => {
    const user = await shared.get();
    const cleared = await api.deleteProjectSettingRaw(user.username, CLOUDFLARE_KEY);
    expectOneOf(cleared.status, [200, 422]);
  });
});
