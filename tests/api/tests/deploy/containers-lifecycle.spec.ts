import { expect, test } from '@/fixtures/test-options';
import { containerIsRunning, containerServiceName } from '@/helpers/deploy-helpers';
import { expectOneOf } from '@/helpers/expect-one-of';
import { waitForCondition } from '@/helpers/retry';
import { skipUnless } from '@/helpers/test-helpers';
import { waitForSiteHttpReady } from '@/helpers/webserver-helpers';
import { Timeouts } from '@/config/timeouts';

test.describe('container lifecycle on a deployed app', () => {
  test.setTimeout(Timeouts.deploy);

  test('project stop and start change whether the site answers', async ({
    api,
    userFactory,
    anonymousRequest,
  }) => {
    const user = await userFactory.createDeployedUser();
    skipUnless(user, 'Could not create a git-deployed user on this engine.');
    const url = `https://${user.domain}/`;

    const listed = await api.listContainers(user.username);
    expect(Array.isArray(listed.data)).toBe(true);
    // createDeployedUser() already waited for the deploy, so an empty compose
    // listing here is the deploy having failed — not a reason to stand down.
    expect(listed.data.length, 'the deployed project reports no containers').toBeGreaterThan(0);

    const stop = await api.runProjectContainerActionRaw(user.username, 'stop');
    expectOneOf(stop.status, [200, 500]);

    await waitForCondition(
      async () => {
        const afterStop = await api.listContainers(user.username);
        return (
          afterStop.data.length === 0 || afterStop.data.every((row) => !containerIsRunning(row))
        );
      },
      {
        timeout: 60_000,
        interval: 2_000,
        message: 'Compose listing still shows a running container after stop',
      }
    );

    const start = await api.runProjectContainerActionRaw(user.username, 'up');
    expectOneOf(start.status, [200, 500]);
    await waitForSiteHttpReady(anonymousRequest, url, { timeout: 90_000 });
  });

  test('a real service can be restarted and its logs read', async ({ api, userFactory }) => {
    const user = await userFactory.createDeployedUser();
    skipUnless(user, 'Could not create a git-deployed user on this engine.');

    const listed = await api.listContainers(user.username);
    const service = listed.data.map(containerServiceName).find((name) => name !== undefined);
    skipUnless(service, 'No compose service name in the container listing.');

    const action = await api.runServiceContainerActionRaw(user.username, service, 'restart');
    expectOneOf(action.status, [200, 422, 500]);

    const unknown = await api.runServiceContainerActionRaw(user.username, 'bad!name', 'restart');
    expect(unknown.status).toBe(422);

    const logs = await api.getContainerLogsRaw(user.username, service, 50);
    expect(logs.status).toBe(200);
    const body = logs.body as { data?: unknown };
    expect(
      body.data === undefined || typeof body.data === 'string' || Array.isArray(body.data)
    ).toBe(true);
    if (typeof body.data === 'string') {
      expect(body.data.length).toBeGreaterThan(0);
    }
  });
});
