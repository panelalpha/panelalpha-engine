import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { skipUnless } from '@/helpers/test-helpers';
import type { CreateTunnelRequest } from '@/types';

/** The validation messages the engine keyed on one field, if any. */
function fieldErrors(body: unknown, field: string): unknown {
  const errors = (body as { errors?: Record<string, unknown> }).errors;
  return errors?.[field];
}

/**
 * Preview tunnels: the engine names them itself, gives them a purpose and a
 * lifetime, and never serves one past that lifetime. The WordPress half of the
 * feature -- canonical links and SSO on the tunnel host -- is not asserted here.
 */
test.describe('preview tunnels', () => {
  test('a ttl below the floor is refused', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser({ tunnel: 'none' });

    const response = await api.createTunnelRaw(user.username, user.domain, {
      purpose: 'preview',
      ttl_minutes: 1,
    });

    await expectStatus(response, 422);
    expect(fieldErrors(response.body, 'ttl_minutes')).toBeTruthy();
  });

  test('a ttl above thirty days is refused', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser({ tunnel: 'none' });

    const response = await api.createTunnelRaw(user.username, user.domain, {
      purpose: 'preview',
      ttl_minutes: 43201,
    });

    await expectStatus(response, 422);
    expect(fieldErrors(response.body, 'ttl_minutes')).toBeTruthy();
  });

  test('an unknown purpose is refused', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser({ tunnel: 'none' });

    const response = await api.createTunnelRaw(user.username, user.domain, {
      purpose: 'staging',
    } as unknown as Partial<CreateTunnelRequest>);

    await expectStatus(response, 422);
    expect(fieldErrors(response.body, 'purpose')).toBeTruthy();
  });

  test('cloudflare without a hostname is refused', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser({ tunnel: 'none' });

    const response = await api.createTunnelRaw(user.username, user.domain, {
      provider: 'cloudflare',
    });

    await expectStatus(response, 422);
    expect(fieldErrors(response.body, 'hostname')).toBeTruthy();
  });

  test('a preview tunnel without a hostname gets a preview label and a day', async ({
    api,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();
    const { data } = await api.getUser(user.username);
    skipUnless(
      data.details.domain?.source === 'panelalpha_online',
      `PanelAlpha Online was not offered (${data.details.domain?.source ?? 'unknown'}: ${user.domain})`
    );

    const created = await api.createTunnel(user.username, user.domain, { purpose: 'preview' });
    try {
      expect(created.data.purpose).toBe('preview');
      expect(created.data.hostname).toMatch(/^preview-[a-z0-9]{8}\.panelalpha\.online$/);
      expect(created.data.valid_until).toBeTruthy();
      expect(Date.parse(String(created.data.valid_until))).toBeCloseTo(
        Date.now() + 24 * 60 * 60 * 1000,
        -4
      );

      const listed = await api.listTunnels(user.username, user.domain);
      const found = listed.data.find((tunnel) => tunnel.hostname === created.data.hostname);
      expect(found?.purpose).toBe('preview');
      expect(found?.valid_until).toBe(created.data.valid_until);
    } finally {
      await api.deleteTunnel(user.username, user.domain, created.data.hostname);
    }
  });

  test('a default tunnel without a ttl does not expire', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser();
    const { data } = await api.getUser(user.username);
    skipUnless(
      data.details.domain?.source === 'panelalpha_online',
      `PanelAlpha Online was not offered (${data.details.domain?.source ?? 'unknown'}: ${user.domain})`
    );

    const created = await api.createTunnel(user.username, user.domain, { purpose: 'default' });
    try {
      expect(created.data.purpose).toBe('default');
      expect(created.data.valid_until).toBeNull();
    } finally {
      await api.deleteTunnel(user.username, user.domain, created.data.hostname);
    }
  });
});
