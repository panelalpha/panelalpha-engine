import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import { deployArchive } from '@/helpers/archive-deploy';
import { expectStatus } from '@/helpers/expect-status';
import { uniqueId } from '@/helpers/random';
import { waitForPage } from '@/helpers/site-page';
import { skipUnless, skipUnlessResolvableSite } from '@/helpers/test-helpers';
import { ENV_COMPOSE, ENV_KEY, servedValue } from '@/test-data/static/env-compose';

/** Each env var change is read back from a page printing the value its container started with. */
test.describe('env vars in the running app', () => {
  test('a stored value waits for the next deploy, a recreate applies it', async ({
    api,
    anonymousRequest,
    userFactory,
  }) => {
    test.setTimeout(Timeouts.deploy * 2);
    const user = await userFactory.createDindUser({ tunnel: 'none' });
    skipUnless(user, 'DinD is not available on this engine.');
    skipUnlessResolvableSite(user.domain);
    const site = `https://${user.domain}/`;
    const first = uniqueId('first-');
    const second = uniqueId('second-');
    const served = (value: string) => (page: { body: string }) => servedValue(page.body) === value;

    await test.step('the value set before the deploy is in the app', async () => {
      await api.setEnvVars(user.username, { env_vars: { [ENV_KEY]: first }, apply: 'none' });
      await deployArchive(api, user.username, { 'docker-compose.yml': ENV_COMPOSE });
      await waitForPage(anonymousRequest, site, served(first), `the app never showed ${first}`);
    });

    await test.step('apply: none stores the value and leaves the app as it is', async () => {
      const stored = await api.setEnvVarsRaw(user.username, {
        env_vars: { [ENV_KEY]: second },
        apply: 'none',
      });
      await expectStatus(stored, 200, 'PUT env-vars apply none');
      const page = await waitForPage(anonymousRequest, site, served(first), 'the app changed');
      expect(servedValue(page.body)).toBe(first);
    });

    await test.step('apply: recreate restarts the app with the stored value', async () => {
      const started = await api.setEnvVarsRaw(user.username, {
        env_vars: { [ENV_KEY]: second },
        apply: 'recreate',
      });
      await expectStatus(started, 202, 'PUT env-vars apply recreate');
      await api.followDeployTask(started.body, 'PUT env-vars');
      await waitForPage(anonymousRequest, site, served(second), `the app never showed ${second}`);
    });

    await test.step('removing the key falls back to the default on the next recreate', async () => {
      const started = await api.setEnvVarsRaw(user.username, {
        env_vars: { [ENV_KEY]: null },
        apply: 'recreate',
      });
      await expectStatus(started, 202, 'PUT env-vars remove the key');
      await api.followDeployTask(started.body, 'PUT env-vars');
      await waitForPage(
        anonymousRequest,
        site,
        served('unset'),
        'the removed key stayed in the app'
      );
    });
  });
});
