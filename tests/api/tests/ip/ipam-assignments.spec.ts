import { expect, test } from '@/fixtures/test-options';
import {
  IPV4_SUBNET,
  IPV6_SUBNET,
  ensureSubnet,
  ipv4In,
  ipv6In,
  isIpamAvailable,
  removeSubnet,
  unassignAllFrom,
} from '@/helpers/ipam-helpers';
import { expectOneOf } from '@/helpers/expect-one-of';

test.describe('IP assignments', () => {
  test.beforeEach(async ({ api }) => {
    test.skip(!(await isIpamAvailable(api)), 'IP management is not available on this engine.');
  });

  test('the assignment listing returns an array', async ({ api }) => {
    expect(Array.isArray((await api.listAssignedIps()).data)).toBe(true);
  });

  test('an IPv4 address is assigned by username and released', async ({ api, setupUser }) => {
    const subnetId = await ensureSubnet(api, IPV4_SUBNET);
    const ipAddress = ipv4In();

    try {
      const assigned = await api.assignIpRaw({
        username: setupUser.username,
        ip_subnet_id: subnetId,
        ip_address: ipAddress,
      });
      expect(assigned.status).toBe(201);
      expect((assigned.body as { data: { ip_address: string } }).data.ip_address).toBe(ipAddress);

      const released = await api.unassignIpRaw({
        username: setupUser.username,
        ip_subnet_id: subnetId,
        ip_address: ipAddress,
      });
      expect(released.status).toBe(200);
    } finally {
      await removeSubnet(api, subnetId);
    }
  });

  test('an IPv4 address is assigned by user_id and appears in the listing', async ({
    api,
    setupUser,
  }) => {
    const subnetId = await ensureSubnet(api, IPV4_SUBNET);
    const ipAddress = '192.168.100.11';

    try {
      // A leftover assignment from an earlier run would collide with this one.
      await unassignAllFrom(api, subnetId);

      const userId = (await api.getUser(setupUser.username)).data.id;

      const assigned = await api.assignIpRaw({
        user_id: userId,
        ip_subnet_id: subnetId,
        ip_address: ipAddress,
      });
      expect(assigned.status).toBe(201);

      const listed = (await api.listAssignedIps()).data.find(
        (assignment) => assignment.ip_address === ipAddress
      );
      expect(listed, `${ipAddress} is not in the assignment listing`).toBeDefined();
      expect(listed?.ip_subnet_id).toBe(subnetId);

      const released = await api.unassignIpRaw({
        username: setupUser.username,
        ip_subnet_id: subnetId,
        ip_address: ipAddress,
      });
      expect(released.status).toBe(200);
    } finally {
      await removeSubnet(api, subnetId);
    }
  });

  test('an IPv6 address is assigned and released', async ({ api, setupUser }) => {
    const subnetId = await ensureSubnet(api, IPV6_SUBNET);

    try {
      await unassignAllFrom(api, subnetId);

      // Addresses are picked at random inside the subnet, so a collision with a
      // leftover assignment is possible; a few attempts make that a non-issue.
      let ipAddress: string | undefined;
      for (let attempt = 0; attempt < 5 && !ipAddress; attempt++) {
        const candidate = ipv6In();
        const assigned = await api.assignIpRaw({
          username: setupUser.username,
          ip_subnet_id: subnetId,
          ip_address: candidate,
        });

        if (assigned.status === 201) {
          expect((assigned.body as { data: { ip_address: string } }).data.ip_address).toBe(
            candidate
          );
          ipAddress = candidate;
        } else {
          expectOneOf(assigned.status, [409, 422]);
        }
      }

      expect(ipAddress, 'no free IPv6 address could be assigned in five attempts').toBeTruthy();

      const released = await api.unassignIpRaw({
        username: setupUser.username,
        ip_subnet_id: subnetId,
        ip_address: ipAddress!,
      });
      expect(released.status).toBe(200);
      expect((released.body as { data: { ip_address: string } }).data.ip_address).toBe(ipAddress);
    } finally {
      await removeSubnet(api, subnetId);
    }
  });
});

test.describe('IP assignment validation', () => {
  test.beforeEach(async ({ api }) => {
    test.skip(!(await isIpamAvailable(api)), 'IP management is not available on this engine.');
  });

  test('assigning without naming a user is refused', async ({ api }) => {
    const subnetId = await ensureSubnet(api, IPV4_SUBNET);

    try {
      const response = await api.assignIpRaw({
        ip_subnet_id: subnetId,
        ip_address: '192.168.100.12',
      });

      expect(response.status).toBe(422);
      expect((response.body as { message?: string }).message).toContain(
        'user_id or username is required'
      );
    } finally {
      await removeSubnet(api, subnetId);
    }
  });

  test('assigning to a user that does not exist is refused', async ({ api }) => {
    const subnetId = await ensureSubnet(api, IPV4_SUBNET);

    try {
      const response = await api.assignIpRaw({
        username: 'nonexistentuser123',
        ip_subnet_id: subnetId,
        ip_address: '192.168.100.13',
      });
      expect(response.status).toBe(422);
    } finally {
      await removeSubnet(api, subnetId);
    }
  });

  test('assigning out of a subnet that does not exist is refused', async ({ api, setupUser }) => {
    const response = await api.assignIpRaw({
      username: setupUser.username,
      ip_subnet_id: 999999,
      ip_address: '192.168.0.1',
    });
    expect(response.status).toBe(422);
  });

  test('assigning a malformed address is refused', async ({ api, setupUser }) => {
    const subnetId = await ensureSubnet(api, IPV4_SUBNET);

    try {
      const response = await api.assignIpRaw({
        username: setupUser.username,
        ip_subnet_id: subnetId,
        ip_address: 'invalid-ip',
      });
      expect(response.status).toBe(422);
    } finally {
      await removeSubnet(api, subnetId);
    }
  });

  test('assigning an address outside the subnet is refused', async ({ api, setupUser }) => {
    const subnetId = await ensureSubnet(api, IPV4_SUBNET);

    try {
      const response = await api.assignIpRaw({
        username: setupUser.username,
        ip_subnet_id: subnetId,
        ip_address: '192.168.200.1',
      });

      expect(response.status).toBe(422);
      expect((response.body as { message?: string }).message).toContain('not within');
    } finally {
      await removeSubnet(api, subnetId);
    }
  });

  test('assigning the same address twice is refused', async ({ api, setupUser }) => {
    const subnetId = await ensureSubnet(api, IPV4_SUBNET);
    const ipAddress = '192.168.100.10';

    try {
      await api.assignIpRaw({
        username: setupUser.username,
        ip_subnet_id: subnetId,
        ip_address: ipAddress,
      });

      const duplicate = await api.assignIpRaw({
        username: setupUser.username,
        ip_subnet_id: subnetId,
        ip_address: ipAddress,
      });

      expect(duplicate.status).toBe(422);
      expect((duplicate.body as { message?: string }).message).toContain('already assigned');
    } finally {
      await removeSubnet(api, subnetId);
    }
  });

  const badUnassignments = [
    [
      'an address that was never assigned',
      (subnetId: number, username: string) => ({
        username,
        ip_subnet_id: subnetId,
        ip_address: '192.168.100.99',
      }),
    ],
    [
      'a user that does not exist',
      (subnetId: number) => ({
        username: 'nonexistentuser123',
        ip_subnet_id: subnetId,
        ip_address: '192.168.100.10',
      }),
    ],
  ] as const;

  for (const [label, buildPayload] of badUnassignments) {
    test(`releasing ${label} is refused`, async ({ api, setupUser }) => {
      const subnetId = await ensureSubnet(api, IPV4_SUBNET);

      try {
        const response = await api.unassignIpRaw(buildPayload(subnetId, setupUser.username));
        expect(response.status).toBe(422);
      } finally {
        await removeSubnet(api, subnetId);
      }
    });
  }

  test('releasing without naming a user is refused', async ({ api }) => {
    const subnetId = await ensureSubnet(api, IPV4_SUBNET);

    try {
      const response = await api.unassignIpRaw({
        ip_subnet_id: subnetId,
        ip_address: '192.168.100.10',
      });

      expect(response.status).toBe(422);
      expect((response.body as { message?: string }).message).toContain(
        'user_id or username is required'
      );
    } finally {
      await removeSubnet(api, subnetId);
    }
  });

  test('releasing from a subnet that does not exist is refused', async ({ api, setupUser }) => {
    const response = await api.unassignIpRaw({
      username: setupUser.username,
      ip_subnet_id: 99999,
      ip_address: '192.168.100.10',
    });

    expect(response.status).toBe(422);
    expect((response.body as { message?: string }).message).toMatch(
      /IP subnet not found|Project not found/
    );
  });
});
