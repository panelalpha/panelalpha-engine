import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { randomDomain, randomUsername } from '@/helpers/random';

test.describe('user clone', () => {
  test('cloning a user creates a distinct active account', async ({
    api,
    userFactory,
    settings,
  }) => {
    const source = await userFactory.createSimpleUser();
    const newUsername = randomUsername(12);
    const newDomain = randomDomain(settings.requireDomain());
    let cloneUsername: string | undefined;

    try {
      const cloned = await test.step('clone the user', async () => {
        const response = await api.cloneUser(source.username, {
          new_username: newUsername,
          domain: newDomain,
        });
        cloneUsername = response.data.username;
        return response;
      });

      await test.step('check the clone response', async () => {
        expect(cloned.data.username, 'clone username').toBe(newUsername);
        expect(cloned.data.domain, 'clone main domain').toBe(newDomain);
        expect(cloned.data.status, 'clone status').toBe('active');
        expect(cloned.data.id, 'the clone has its own id').not.toBe(
          (await api.getUser(source.username)).data.id
        );
      });

      await test.step('read the clone back', async () => {
        const fetched = await api.getUser(newUsername);
        expect(fetched.data.domain, 'stored clone domain').toBe(newDomain);
      });
    } finally {
      if (cloneUsername) {
        await api.deleteUserSafe(cloneUsername);
      }
      await userFactory.deleteUser(source.username);
    }
  });

  test('cloning an unknown user returns 404', async ({ api }) => {
    const response = await api.cloneUserRaw('nosuchuser999');
    await expectStatus(response, 404);
  });

  test('an invalid clone username is rejected', async ({ api, userFactory, settings }) => {
    const source = await userFactory.createSimpleUser();

    try {
      const response = await api.cloneUserRaw(source.username, {
        new_username: '1bad',
        domain: randomDomain(settings.requireDomain()),
      });
      expect([400, 422], 'an invalid clone username is rejected with 400 or 422').toContain(
        response.status
      );
    } finally {
      await userFactory.deleteUser(source.username);
    }
  });
});
