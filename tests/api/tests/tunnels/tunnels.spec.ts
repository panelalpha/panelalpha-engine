import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { expectStatus } from '@/helpers/expect-status';
import { sharedProject } from '@/helpers/shared-project';

test.describe('domain tunnels', () => {
  // None of these tests reads the tunnel list or creates a tunnel; the empty-list test needs
  // `tunnel: none` and keeps its own project.
  const shared = sharedProject('simple');
  test.afterAll(() => shared.dispose());

  test('the tunnel list starts empty', async ({ api, userFactory }) => {
    // Default create records a panelalpha.online tunnel. `none` is the name
    // that is not a tunnel, so the list is actually empty.
    const user = await userFactory.createSimpleUser({ tunnel: 'none' });
    const listed = await api.listTunnels(user.username, user.domain);
    expect(Array.isArray(listed.data)).toBe(true);
    expect(listed.data).toEqual([]);
  });

  test('an unknown project is 404', async ({ api }) => {
    await expectStatus(await api.listTunnelsRaw('nosuchuser999', 'missing.example.test'), 404);
  });

  test('an unknown domain is 404', async ({ api }) => {
    const user = await shared.get();
    await expectStatus(await api.listTunnelsRaw(user.username, 'missing.example.test'), 404);
  });

  test('a hostname that is not a public tunnel name is refused', async ({ api }) => {
    const user = await shared.get();
    const response = await api.createTunnelRaw(user.username, user.domain, {
      hostname: 'not a hostname',
      provider: 'panelalpha',
    });
    expectOneOf(response.status, [400, 422]);
  });

  test('cloudflare without a project token is refused', async ({ api }) => {
    const user = await shared.get();
    const response = await api.createTunnelRaw(user.username, user.domain, {
      hostname: `cf-${user.username}.example.test`,
      provider: 'cloudflare',
    });
    expectOneOf(response.status, [400, 422]);
  });

  test('deleting a missing tunnel is 404', async ({ api }) => {
    const user = await shared.get();
    await expectStatus(
      await api.deleteTunnelRaw(user.username, user.domain, 'missing.panelalpha.online'),
      404
    );
  });
});
