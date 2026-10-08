import { expect, test } from '@/fixtures/test-options';
import { isDindUser } from '@/helpers/sss-feature-helpers';
import { skipUnless } from '@/helpers/test-helpers';
import { expectStatus } from '@/helpers/expect-status';

test.describe('deploy log', () => {
  test('a non-DinD user cannot read the deploy log', async ({ api, setupUser }) => {
    const response = await api.getDeployLogRaw(setupUser.username);
    expect([403, 404], 'the deploy log is refused').toContain(response.status);
  });

  test('cancelling when nothing is running returns 409 for a DinD user', async ({
    api,
    sharedEmptyDind,
  }) => {
    const user = await sharedEmptyDind.get();
    skipUnless(user, 'DinD is not available on this engine.');

    test.skip(!(await isDindUser(api, user.username)), 'DinD is not available on this engine.');

    const deploy = await api.getDeployLog(user.username);
    expect(
      ['none', 'running', 'success', 'failed', 'cancelled'],
      'the deploy status is a known one'
    ).toContain(deploy.data.status);

    if (deploy.data.status !== 'running') {
      const cancel = await api.cancelDeployRaw(user.username);
      await expectStatus(cancel, 409);
    }
  });
});
