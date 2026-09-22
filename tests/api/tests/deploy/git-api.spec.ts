import { expect, test } from '@/fixtures/test-options';
import { skipUnless } from '@/helpers/test-helpers';
import { Timeouts } from '@/config/timeouts';

test.describe('git API on a deploy-managed project', () => {
  test.setTimeout(Timeouts.deploy);

  test('status is deploy-managed and disconnect is refused', async ({ api, userFactory }) => {
    const user = await userFactory.createDeployedUser();
    skipUnless(user, 'Could not create a git-deployed user on this engine.');

    const status = await api.gitStatus(user.username);
    expect(status.data.managed_by).toBe('deploy');
    expect(status.data.repository_exists).toBe(true);

    const disconnect = await api.gitDisconnectRaw(user.username);
    expect(disconnect.status).toBe(422);
    expect(JSON.stringify(disconnect.body)).toMatch(/managed by deploy/i);

    // Push stays open on a deploy-managed project: only disconnect is refused.
    const push = await api.gitPushRaw(user.username);
    expect(push.status).toBe(200);
    const pushed = push.body as { data?: { managed_by?: string; nothing_to_push?: boolean } };
    expect(pushed.data?.managed_by).toBe('deploy');
    expect(pushed.data?.nothing_to_push).toBe(true);
  });
});
