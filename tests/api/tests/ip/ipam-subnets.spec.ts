import { expect, test } from '@/fixtures/test-options';
import type { SubnetSpec } from '@/helpers/ipam-helpers';
import { ipamSubnetListSchema } from '@/schemas';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';
import {
  IPV4_SUBNET,
  IPV6_SUBNET,
  SHARED_IPV4_SUBNET,
  ensureSubnet,
  isIpamAvailable,
  removeSubnet,
} from '@/helpers/ipam-helpers';

/**
 * IP management is an optional module; where it is absent every endpoint answers
 * 404 and these tests skip.
 *
 * Each test owns the subnet it needs and removes it afterwards, so no spec
 * depends on another having run — the original suite created subnets in one
 * feature file and deleted them in another.
 */
test.describe('IP subnets', () => {
  test.beforeEach(async ({ api }) => {
    test.skip(!(await isIpamAvailable(api)), 'IP management is not available on this engine.');
  });

  test('the subnet listing is paginated', async ({ api }) => {
    const listing = await api.listSubnets();
    validateParsedApiResponse(listing, ipamSubnetListSchema);
    expect(listing).toHaveProperty('meta');
  });

  const subnets: [label: string, subnet: SubnetSpec, shared: boolean][] = [
    ['an IPv4 subnet', IPV4_SUBNET, false],
    ['an IPv6 subnet', IPV6_SUBNET, false],
    ['a shared IPv4 subnet', SHARED_IPV4_SUBNET, true],
  ];

  for (const [label, subnet, shared] of subnets) {
    test(`${label} can be added, listed and deleted`, async ({ api }) => {
      const subnetId = await ensureSubnet(api, subnet, { shared });

      try {
        const listed = (await api.listSubnets()).data.find(
          (candidate) => candidate.id === subnetId
        );

        expect(listed, `subnet ${subnet.ip}/${subnet.mask} is not in the listing`).toBeDefined();
        expect(listed?.ip).toBe(subnet.ip);
        expect(listed?.mask).toBe(subnet.mask);

        if (shared) {
          // The listing reports the flag as 1 while the create response uses true.
          expect(Boolean(listed?.is_shared), 'the subnet is not marked shared').toBe(true);
        }
      } finally {
        expect(await removeSubnet(api, subnetId)).toBe(200);
      }
    });
  }
});

test.describe('IP subnet validation', () => {
  test.beforeEach(async ({ api }) => {
    test.skip(!(await isIpamAvailable(api)), 'IP management is not available on this engine.');
  });

  test('the same subnet cannot be added twice', async ({ api }) => {
    const subnetId = await ensureSubnet(api, IPV4_SUBNET);

    try {
      const duplicate = await api.addSubnetRaw({ ip: IPV4_SUBNET.ip, mask: IPV4_SUBNET.mask });

      expect(duplicate.status).toBe(422);
      expect((duplicate.body as { message?: string }).message).toContain('already exist');
    } finally {
      await removeSubnet(api, subnetId);
    }
  });

  const invalidMasks = [
    [
      'an IPv4 mask above 32',
      { ip: '192.168.101.0', mask: 33 },
      'IPv4 mask must be between 0 and 32',
    ],
    [
      'an IPv6 mask above 128',
      { ip: '2001:db8:1::', mask: 129 },
      'IPv6 mask must be between 0 and 128',
    ],
  ] as const;

  for (const [label, subnet, message] of invalidMasks) {
    test(`${label} is refused`, async ({ api }) => {
      const response = await api.addSubnetRaw(subnet);

      expect(response.status).toBe(422);
      expect((response.body as { message?: string }).message).toContain(message);
    });
  }

  test('deleting an unknown subnet returns 404', async ({ api }) => {
    expect((await api.deleteSubnetRaw(999999)).status).toBe(404);
  });

  /** Deleting a subnet out from under a live assignment would strand the user's IP. */
  test('a subnet with an address still assigned cannot be deleted', async ({ api, setupUser }) => {
    const subnetId = await ensureSubnet(api, IPV4_SUBNET);
    const ipAddress = '192.168.100.10';

    try {
      await api.assignIpRaw({
        username: setupUser.username,
        ip_subnet_id: subnetId,
        ip_address: ipAddress,
      });

      const deletion = await api.deleteSubnetRaw(subnetId);
      expect(deletion.status).toBe(422);
      expect((deletion.body as { message?: string }).message).toContain('assigned');
    } finally {
      await removeSubnet(api, subnetId);
    }
  });
});
