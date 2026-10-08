import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';

test.describe('deploy hook without a git checkout', () => {
  test('a project that is not connected to git is refused', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser();

    await test.step('creating a hook is refused', async () => {
      const created = await api.createDeployHookRaw(user.username);
      await expectStatus(created, 422);
      expect(JSON.stringify(created.body), 'the refusal says the project is not connected').toMatch(
        /not connected to git/i
      );
    });

    // Looking up, rotating or deleting does not require a connection: there
    // is simply no hook on this checkout.
    for (const [label, call] of [
      ['looking up', () => api.getDeployHookRaw(user.username)],
      ['rotating', () => api.rotateDeployHookRaw(user.username)],
      ['deleting', () => api.deleteDeployHookRaw(user.username)],
    ] as const) {
      await test.step(`${label} the hook finds none`, async () => {
        const response = await call();
        await expectStatus(response, 404);
        expect(JSON.stringify(response.body), 'the answer says there is no hook').toMatch(
          /no deploy hook/i
        );
      });
    }
  });

  test('an unknown project and an unknown provider are refused', async ({ api }) => {
    const missing = await api.createDeployHookRaw(`nosuchuser${Date.now()}`);
    await expectStatus(missing, 404);

    const user = await api.createDeployHookRaw('nosuchuserzz', { provider: 'not-a-host' });
    await expectStatus(user, 422);
  });
});
