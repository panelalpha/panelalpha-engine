import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { expectStatus } from '@/helpers/expect-status';
import { stagingUserPayload } from '@/helpers/staging-helpers';

test.describe('user staging', () => {
  test('staging a PHP user is accepted or refused as unsupported', async ({ api, userFactory }) => {
    const source = await userFactory.createSimpleUser();
    let stagingUsername: string | undefined;
    try {
      const response = await api.createStagingRaw(source.username, {});
      expectOneOf(response.status, [202, 422]);
      if (response.status === 422) {
        expect(JSON.stringify(response.body)).toMatch(/not supported/i);
        return;
      }
      stagingUsername = stagingUserPayload(response.body as unknown).username;
      expect(stagingUsername).toBeTruthy();
    } finally {
      if (stagingUsername) {
        await api.deleteUserSafe(stagingUsername);
      }
      await api.deleteUserSafe(source.username);
    }
  });

  test('staging unknown user returns 404', async ({ api }) => {
    const response = await api.createStagingRaw('nosuchuser999', {});
    await expectStatus(response, 404);
  });

  test('push without target is 422', async ({ api, userFactory }) => {
    const source = await userFactory.createSimpleUser();
    try {
      const response = await api.pushProjectRaw(source.username, '');
      expect([400, 422]).toContain(response.status);
    } finally {
      await userFactory.deleteUser(source.username);
    }
  });
});
