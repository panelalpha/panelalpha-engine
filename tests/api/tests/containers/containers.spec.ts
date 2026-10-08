import { expect, test } from '@/fixtures/test-options';
import { isDindUser } from '@/helpers/sss-feature-helpers';
import { skipUnless } from '@/helpers/test-helpers';
import { expectStatus } from '@/helpers/expect-status';

test.describe('containers', () => {
  test('a non-DinD user is refused container management', async ({ api, setupUser }) => {
    const response = await api.listContainersRaw(setupUser.username);
    expect([403, 404], 'container management is refused').toContain(response.status);
  });

  test('a DinD user can list containers and read deploy state', async ({
    api,
    sharedEmptyDind,
  }) => {
    const user = await sharedEmptyDind.get();
    skipUnless(user, 'DinD is not available on this engine.');

    test.skip(
      !(await isDindUser(api, user.username)),
      'Container management is only available for DinD users on this engine.'
    );

    await test.step('list the containers', async () => {
      const listed = await api.listContainers(user.username);
      expect(Array.isArray(listed.data), 'containers is an array').toBe(true);
    });

    await test.step('read the deploy state', async () => {
      const deploy = await api.getDeployLog(user.username);
      expect(typeof deploy.data.status, 'deploy status is a string').toBe('string');
      expect(typeof deploy.data.next_offset, 'deploy next_offset is a number').toBe('number');
    });

    await test.step('cancel the deploy', async () => {
      const cancel = await api.cancelDeployRaw(user.username);
      expect([200, 409], 'cancel answers 200 or 409').toContain(cancel.status);
    });

    await test.step('an invalid service name is refused', async () => {
      const invalidService = await api.get(`projects/${user.username}/containers/bad!name/logs`);
      await expectStatus(invalidService, 422);
    });
  });
});
