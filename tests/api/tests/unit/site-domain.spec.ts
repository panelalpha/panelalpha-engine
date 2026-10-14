import { expect, test } from '@/fixtures/test-options';
import {
  fetchEngineSiteInfo,
  ipv4FromPanelAlphaDirectZone,
  isLoopbackIp,
  isThirdPartyIpZone,
  onlineLabel,
  pickAddonParent,
  pickSiteIpv4,
  projectNameResolves,
  resolveSiteBaseDomain,
  toPanelAlphaDirectZone,
  wwwAliasWouldAnswer,
} from '@/config/site-domain';

test.describe('panelalpha.online labels', () => {
  test('reads the registered label the way DomainPlan does', () => {
    expect(onlineLabel('shop-4f2a.panelalpha.online')).toBe('shop-4f2a');
    expect(onlineLabel('SHOP.panelalpha.online.')).toBe('shop');
  });

  test('rejects names the proxy would not have registered', () => {
    expect(onlineLabel('a.b.panelalpha.online')).toBeUndefined();
    expect(onlineLabel('shop.example.com')).toBeUndefined();
    expect(onlineLabel('panelalpha.online')).toBeUndefined();
  });

  test('a www alias would not answer under the tunnel zone', () => {
    expect(wwwAliasWouldAnswer('shop-4f2a.panelalpha.online')).toBe(false);
    expect(wwwAliasWouldAnswer('shop.203-0-113-7.panelalpha.direct')).toBe(true);
    expect(wwwAliasWouldAnswer('shop.acme.com')).toBe(true);
  });
});

test.describe('panelalpha.direct zone names', () => {
  test('maps an IPv4 address the way DomainPlan does', () => {
    expect(toPanelAlphaDirectZone('192.0.2.10')).toBe('192-0-2-10.panelalpha.direct');
  });

  test('reads the address back out of a zone parent', () => {
    expect(ipv4FromPanelAlphaDirectZone('198-51-100-10.panelalpha.direct')).toBe('198.51.100.10');
  });
});

test.describe('projectNameResolves', () => {
  test('a direct name resolves even though it is not an online label', () => {
    expect(projectNameResolves('shop.10-10-10-44.panelalpha.direct')).toBe(true);
    expect(onlineLabel('shop.10-10-10-44.panelalpha.direct')).toBeUndefined();
  });

  test('every generated rung except local resolves', () => {
    expect(projectNameResolves('shop-4f2a.panelalpha.online')).toBe(true);
    expect(projectNameResolves('shop.engine.example.com')).toBe(true);
    expect(projectNameResolves('shop.local')).toBe(false);
  });

  test('ignores case and a trailing dot', () => {
    expect(projectNameResolves('SHOP.LOCAL.')).toBe(false);
  });
});

test.describe('loopback addresses', () => {
  test('treats Debian /etc/hosts 127.0.1.1 as loopback', () => {
    expect(isLoopbackIp('127.0.1.1')).toBe(true);
  });

  test('treats 127.0.0.1 and IPv6 loopback as loopback', () => {
    expect(isLoopbackIp('127.0.0.1')).toBe(true);
    expect(isLoopbackIp('::1')).toBe(true);
  });

  test('does not treat a public address as loopback', () => {
    expect(isLoopbackIp('192.0.2.10')).toBe(false);
  });
});

test.describe('third-party IP zones', () => {
  test('recognises sslip.io leftovers so they are not used as the test parent', () => {
    expect(isThirdPartyIpZone('192.0.2.10.sslip.io')).toBe(true);
    expect(isThirdPartyIpZone('192-0-2-10.sslip.io')).toBe(true);
    expect(isThirdPartyIpZone('198-51-100-10.nip.io')).toBe(true);
  });

  test('does not treat panelalpha.direct as a third-party zone', () => {
    expect(isThirdPartyIpZone('198-51-100-10.panelalpha.direct')).toBe(false);
  });
});

test.describe('pickAddonParent', () => {
  test('an explicit DOMAIN wins', () => {
    expect(
      pickAddonParent({
        explicit: 'sites.example.com',
        defaultIpv4: '198.51.100.10',
      })
    ).toBe('sites.example.com');
  });

  test('maps a bare IPv4 override onto panelalpha.direct', () => {
    expect(pickAddonParent({ explicit: '198.51.100.10' })).toBe('198-51-100-10.panelalpha.direct');
  });

  test('ignores an sslip.io sites_base_domain leftover', () => {
    expect(
      pickAddonParent({
        sitesBaseDomain: '198-51-100-10.sslip.io',
        defaultIpv4: '198.51.100.10',
      })
    ).toBe('198-51-100-10.panelalpha.direct');
  });

  test('uses the operator sites_base_domain when it is not sslip/nip', () => {
    expect(
      pickAddonParent({
        sitesBaseDomain: 'sites.example.com',
        defaultIpv4: '198.51.100.10',
      })
    ).toBe('sites.example.com');
  });

  test('uses cert_domain when no sites base is set', () => {
    expect(
      pickAddonParent({
        certDomain: 'engine.example.com',
        defaultIpv4: '198.51.100.10',
      })
    ).toBe('engine.example.com');
  });

  test('falls back to {dashed-ip}.panelalpha.direct', () => {
    expect(pickAddonParent({ defaultIpv4: '198.51.100.10' })).toBe(
      '198-51-100-10.panelalpha.direct'
    );
  });
});

test.describe('pickSiteIpv4', () => {
  test('prefers the engine when API_BASE_URL resolved to 127.0.1.1', () => {
    expect(
      pickSiteIpv4({
        resolvedFromApiUrl: '127.0.1.1',
        engineDefaultIpv4: '198.51.100.10',
      })
    ).toBe('198.51.100.10');
  });

  test('prefers the engine when API_BASE_URL points at a proxy', () => {
    expect(
      pickSiteIpv4({
        resolvedFromApiUrl: '203.0.113.10',
        engineDefaultIpv4: '198.51.100.10',
      })
    ).toBe('198.51.100.10');
  });

  test('keeps the resolved address when the engine address is unknown', () => {
    expect(pickSiteIpv4({ resolvedFromApiUrl: '192.0.2.10' })).toBe('192.0.2.10');
  });
});

test.describe('resolveSiteBaseDomain', () => {
  test('asks the engine when the API hostname maps to 127.0.1.1', async () => {
    const resolved = await resolveSiteBaseDomain('https://engine.example.com:2011/api/', {
      lookup: () => Promise.resolve('127.0.1.1'),
      fetchEngineInfo: () => Promise.resolve({ defaultIpv4: '198.51.100.10' }),
    });

    expect(resolved?.parent).toBe('198-51-100-10.panelalpha.direct');
  });

  test('uses a public IP in API_BASE_URL as panelalpha.direct without engine info', async () => {
    const resolved = await resolveSiteBaseDomain('https://192.0.2.10:2011/api/', {});

    expect(resolved?.parent).toBe('192-0-2-10.panelalpha.direct');
  });
});

test.describe('fetchEngineSiteInfo', () => {
  test('reads naming fields from GET /system/info', async () => {
    const info = await fetchEngineSiteInfo('https://engine.example.com:2011/api/', '1|token', {
      fetch: (url, init) => {
        expect(url).toBe('https://engine.example.com:2011/api/system/info');
        expect(init.headers?.Authorization).toBe('Bearer 1|token');
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                default_ipv4: '198.51.100.10',
                cert_domain: '198-51-100-10.panelalpha.direct',
                api_url: 'https://198.51.100.10:2011/api',
              },
            }),
            { status: 200 }
          )
        );
      },
    });

    expect(info?.defaultIpv4).toBe('198.51.100.10');
    expect(info?.certDomain).toBe('198-51-100-10.panelalpha.direct');
    expect(info?.apiUrl).toBe('https://198.51.100.10:2011/api');
  });

  test('returns undefined when the engine is unreachable', async () => {
    const info = await fetchEngineSiteInfo('https://engine.example.com:2011/api/', '1|token', {
      fetch: () => Promise.reject(new Error('ECONNREFUSED')),
    });

    expect(info).toBeUndefined();
  });
});
