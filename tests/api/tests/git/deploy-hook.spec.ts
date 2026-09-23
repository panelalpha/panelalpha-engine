import { expect, test } from '@/fixtures/test-options';

test.describe('deploy hook without a git checkout', () => {
  test('a project that is not connected to git is refused', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser();

    const created = await api.createDeployHookRaw(user.username);
    expect(created.status).toBe(422);
    expect(JSON.stringify(created.body)).toMatch(/not connected to git/i);

    // Looking up, rotating or deleting does not require a connection: there
    // is simply no hook on this checkout.
    for (const call of [
      () => api.getDeployHookRaw(user.username),
      () => api.rotateDeployHookRaw(user.username),
      () => api.deleteDeployHookRaw(user.username),
    ]) {
      const response = await call();
      expect(response.status).toBe(404);
      expect(JSON.stringify(response.body)).toMatch(/no deploy hook/i);
    }
  });

  test('an unknown project and an unknown provider are refused', async ({ api }) => {
    const missing = await api.createDeployHookRaw(`nosuchuser${Date.now()}`);
    expect(missing.status).toBe(404);

    const user = await api.createDeployHookRaw('nosuchuserzz', { provider: 'not-a-host' });
    expect(user.status).toBe(422);
  });
});
