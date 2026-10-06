import { expect, test } from '@/fixtures/test-options';
import { isPrivateOrLocalIp } from '@/helpers/real-ip-helpers';
import { skipUnless } from '@/helpers/test-helpers';

/**
 * DomainPlan without `tunnel` tries panelalpha.online first, then
 * panelalpha.direct. These specs pin both rungs; default factory creates omit
 * `tunnel` so they follow the same ladder as the engine.
 */
test.describe('PanelAlpha Online', () => {
  test('create without a domain allocates a panelalpha.online name', async ({
    api,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();
    const { data } = await api.getUser(user.username);
    const source = data.details.domain?.source;
    skipUnless(
      source !== 'panelalpha_direct' && source !== 'local' && source !== 'sites_base_domain',
      `PanelAlpha Online was not offered (${source ?? 'unknown'}: ${data.details.domain?.fallback_reason ?? user.domain})`
    );

    expect(user.domain).toMatch(/\.panelalpha\.online$/);
    expect(source).toBe('panelalpha_online');
    expect(data.details.domain?.tls_terminated_at).toBe('proxy');
    expect(data.details.domain?.publicly_resolvable).toBe(true);
  });
});

test.describe('PanelAlpha Direct', () => {
  test('tunnel none names the project under panelalpha.direct', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser({ tunnel: 'none' });
    const { data } = await api.getUser(user.username);

    expect(user.domain).toMatch(/\.panelalpha\.direct$/);
    expect(data.details.domain?.source).toBe('panelalpha_direct');
    expect(data.details.domain?.tls_terminated_at).toBe('engine');
    // The name spells the address it resolves to (<label>.10-0-0-5.panelalpha.direct);
    // a private one answers on that network only, which the engine reports as not public.
    const address = user.domain.split('.').at(-3)?.replace(/-/g, '.');
    test.skip(
      isPrivateOrLocalIp(address),
      `${user.domain} resolves to the private address ${address}; publicly_resolvable is false there by design.`
    );
    expect(data.details.domain?.publicly_resolvable).toBe(true);
  });
});
