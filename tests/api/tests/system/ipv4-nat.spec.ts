import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { randomIpv4 } from '@/helpers/random';

/**
 * NAT mode lets the engine serve sites on a public address that the host itself
 * does not hold, by mapping that public address to a local one.
 *
 * `default_ipv4` is engine-wide state: leaving it pointed at a test address
 * would break provisioning for every spec that follows, so each test restores
 * what it found in a finally block.
 */

/** TEST-NET-3 (RFC 5737) — routable nowhere, so it is genuinely "non-local". */
const NON_LOCAL_IPV4 = '203.0.113.50';

test.describe('IPv4 NAT maps', () => {
  test('a map is created, listed and deleted', async ({ api }) => {
    const localIp = randomIpv4();
    const publicIp = randomIpv4();

    const created = await api.upsertIpv4NatMap({ local_ip: localIp, public_ip: publicIp });
    const id = created.data.id;
    expect(id, 'the engine returned no id for the new NAT map').toBeDefined();

    try {
      const listed = (await api.getIpv4NatMaps()).data;
      expect(
        listed.some((map) => map.local_ip === localIp && map.public_ip === publicIp),
        `${localIp} -> ${publicIp} is missing from the NAT map listing`
      ).toBe(true);

      await api.deleteIpv4NatMap(id!);

      expect((await api.getIpv4NatMaps()).data.some((map) => map.id === id)).toBe(false);
    } finally {
      await api.deleteIpv4NatMap(id!).catch(() => undefined);
    }
  });

  /**
   * Auto-discovery is a host-wide write, not a read.
   *
   * It inspects the host's addressing, writes what it concludes into the NAT
   * map table, and rebuilds every vhost from it. On 2026-09-17 it ran here
   * against an engine that holds its public address directly, decided the box
   * was behind NAT, wrote `172.25.0.1 -> 95.217.155.125`, rebound every vhost
   * to the Docker gateway and took the public address off the air — for the
   * live sites too, not just the suite's. It then answered 500.
   *
   * So it is gated like its sibling below, and for the same reason: the
   * default run must not reconfigure the host's networking. The engine bug it
   * uncovered is a separate matter; this gate is about blast radius.
   */
  test('auto-discovery returns a list of maps', async ({ api }) => {
    test.skip(
      process.env.ALLOW_NETWORK_MUTATION !== '1',
      'Set ALLOW_NETWORK_MUTATION=1 to run NAT auto-discovery. It rewrites the NAT map table and rebinds every vhost.'
    );

    const before = (await api.getIpv4NatMaps()).data;

    let response;
    try {
      response = await api.rebuildIpv4NatMaps();
    } catch (error) {
      // Engines without auto-discovery answer 404 or 422 for this route.
      expect(String(error)).toMatch(/unprocessable|422|not found|404/i);
      test.skip(true, 'This engine has no NAT auto-discovery endpoint.');
      return;
    }

    const maps = Array.isArray(response.data) ? response.data : response.data.maps;
    expect(Array.isArray(maps)).toBe(true);

    // Whatever it discovered, it must not have invented a mapping for an
    // address the host already holds: that is the shape that takes the public
    // address off the air.
    const after = (await api.getIpv4NatMaps()).data;
    const invented = after.filter((map) => !before.some((existing) => existing.id === map.id));
    expect(
      invented.map((map) => `${map.local_ip} -> ${map.public_ip}`),
      'auto-discovery added NAT maps that were not there before'
    ).toEqual([]);
  });
});

test.describe('provisioning behind NAT', () => {
  test.beforeEach(() => {
    test.skip(
      process.env.ALLOW_NETWORK_MUTATION !== '1',
      'Set ALLOW_NETWORK_MUTATION=1 to mutate default_ipv4. This describe points it at TEST-NET and rebuilds vhosts.'
    );
  });

  const provisioningCases = [
    ['without a NAT map', false],
    ['with a NAT map for the public address', true],
  ] as const;

  for (const [label, withNatMap] of provisioningCases) {
    test(`a shared-IP user can be created ${label}`, async ({ api, settings }) => {
      const original = (await api.getSystemInfo()).data.default_ipv4 ?? '';
      const username = `nattest${Date.now().toString().slice(-6)}`;
      let natMapId: number | undefined;

      try {
        await api.updateSystemNetworkConfig({ default_ipv4: NON_LOCAL_IPV4 });

        if (withNatMap) {
          // The map has to translate the machine's real address to the
          // non-local one. Mapping the non-local address to itself would flip
          // NAT mode on without exercising public-to-local resolution at all.
          const localIp = original || randomIpv4();
          natMapId = (await api.upsertIpv4NatMap({ local_ip: localIp, public_ip: NON_LOCAL_IPV4 }))
            .data.id;
        }

        const created = await api.createUserRaw({
          username,
          domain: `${username}.${settings.requireDomain()}`,
          dedicated_ipv4: false,
        });

        expectOneOf(
          created.status,
          [200, 201, 202],
          `provisioning ${label} failed while default_ipv4 was non-local`
        );
      } finally {
        await api.deleteUserSafe(username);
        if (natMapId !== undefined) {
          await api.deleteIpv4NatMap(natMapId).catch(() => undefined);
        }
        // Engine-wide state: restoring this matters more than the assertions above.
        await api.updateSystemNetworkConfig({ default_ipv4: original });
      }
    });
  }
});
