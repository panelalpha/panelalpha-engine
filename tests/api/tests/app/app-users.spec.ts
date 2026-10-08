import { expect, test } from '@/fixtures/test-options';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { expectStatus } from '@/helpers/expect-status';
import { isDindUser } from '@/helpers/sss-feature-helpers';
import { randomEmail, randomPassword, randomUsername } from '@/helpers/random';
import { skipUnless } from '@/helpers/test-helpers';

test.describe('app users', () => {
  test('a non-DinD user is refused app endpoints', async ({ api, setupUser }) => {
    const info = await api.getAppInfoRaw(setupUser.username);
    const roles = await api.getAppRolesRaw(setupUser.username);
    const users = await api.listAppUsersRaw(setupUser.username);
    const health = await api.getAppHealthRaw(setupUser.username);

    expect([403, 404, 422], 'app info is refused').toContain(info.status);
    expect([403, 404, 422], 'app roles are refused').toContain(roles.status);
    expect([403, 404, 422], 'the app user list is refused').toContain(users.status);
    expect([403, 404, 422], 'app health is refused').toContain(health.status);
  });

  test('a DinD user can read app info and roles when the app is present', async ({
    api,
    userFactory,
  }) => {
    const user = await userFactory.createDindUser();
    skipUnless(user, 'DinD is not available on this engine.');
    const username = user.username;

    test.skip(!(await isDindUser(api, username)), 'DinD is not available on this engine.');

    const info = await api.getAppInfoRaw(username);
    const roles = await api.getAppRolesRaw(username);
    const users = await api.listAppUsersRaw(username);

    // Without a deployed app these land on 422; with an app they are 200.
    expect([200, 422], 'app info answers 200 or 422').toContain(info.status);
    expect([200, 422], 'app roles answer 200 or 422').toContain(roles.status);
    expect([200, 422], 'the app user list answers 200 or 422').toContain(users.status);

    if (roles.status === 200) {
      const roleList = (roles.body as { data: string[] }).data;
      expect(Array.isArray(roleList), 'the roles payload is an array').toBe(true);

      test.skip(roleList.length === 0, 'The app reports no roles to create users with.');

      const userId = await test.step('create an app user', async () => {
        const login = randomUsername(10);
        const createdUser = await api.createAppUserRaw(username, {
          login,
          email: randomEmail(),
          password: randomPassword(12),
          role: roleList[0],
        });

        // AppUserController::store answers 201 for a valid payload and 422 for
        // every app-side failure (rethrowAppException), so a 422 here means the
        // app refused a user it listed a role for, and 5xx is never expected.
        await expectStatus(createdUser, 201);
        return String((createdUser.body as { data: { id: string | number } }).data.id);
      });
      const cleanup = new CleanupSteps();
      try {
        await test.step('reset the app user password', async () => {
          await api.resetAppUserPassword(username, userId, randomPassword(12));
        });
        await test.step('create a one-click login link', async () => {
          const sso = await api.createAppUserSso(username, userId);
          expect(typeof sso.url, 'the SSO url is a string').toBe('string');
          expect(sso.url.length, 'the SSO url is not empty').toBeGreaterThan(0);
        });
      } catch (error) {
        cleanup.bodyFailed(error);
        throw error;
      } finally {
        await cleanup.run('delete the app user', () => api.deleteAppUser(username, userId));
        await cleanup.finish();
      }
    }
  });

  test('an invalid app install payload is rejected', async ({ api, setupUser }) => {
    const response = await api.installAppRaw(setupUser.username, {
      url: 'not-a-url',
      title: '',
      admin_user: '',
      admin_email: 'bad',
      admin_password: 'short',
    });
    expect([403, 404, 422], 'an invalid install payload is refused').toContain(response.status);
  });

  test('a valid install payload on DinD is accepted or the app has no installer', async ({
    api,
    userFactory,
  }) => {
    const user = await userFactory.createDindUser();
    skipUnless(user, 'DinD is not available on this engine.');

    const response = await api.installAppRaw(user.username, {
      url: `https://${user.domain}`,
      title: 'API test site',
      admin_user: 'admin',
      admin_email: 'admin@example.test',
      admin_password: 'SecurePass123',
    });
    expect([204, 422], 'install is accepted or the app has no installer').toContain(
      response.status
    );
  });
});
