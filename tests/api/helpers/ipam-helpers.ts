import type { EngineApi } from '@/clients/engine-api';
import { expectStatus } from './expect-status';
import { skipTest } from './test-helpers';

/**
 * Documentation ranges, so nothing here can collide with a real address the
 * engine actually routes: RFC 1918 for IPv4, RFC 3849 for IPv6.
 */
export const IPV4_SUBNET = { ip: '192.168.100.0', mask: 24 } as const;
export const IPV6_SUBNET = { ip: '2001:db8::', mask: 64 } as const;
export const SHARED_IPV4_SUBNET = { ip: '192.168.150.0', mask: 24 } as const;

export interface SubnetSpec {
  ip: string;
  mask: number;
}

/**
 * Whether the engine exposes IP management at all. Only 404 (the route is not
 * registered) is a definite absence; any other answer that is not 200 throws.
 */
export async function isIpamAvailable(api: EngineApi): Promise<boolean> {
  const response = await api.listSubnetsRaw();
  if (response.status === 404) {
    return false;
  }
  await expectStatus(response, 200, 'GET /ip/subnets');
  return true;
}

/**
 * The engine refuses a subnet the host has no route for. A host without a
 * default IPv6 route cannot carry an IPv6 subnet at all, so that refusal is
 * the host's precondition, not a failure.
 */
function skipWhenHostCannotRoute(subnet: SubnetSpec, status: number, body: unknown): void {
  const message = (body as { message?: string } | null)?.message ?? '';
  if (status === 422 && message.includes('No default route found for IPv6')) {
    skipTest(
      `The host has no default IPv6 route, so the engine refuses IPv6 subnet ${subnet.ip}/${subnet.mask}: ${message}`
    );
  }
}

/**
 * Returns the id of `subnet`, creating it if it is not there yet.
 *
 * Idempotent on purpose: a previous run that failed part-way leaves the subnet
 * behind, and the engine answers 422 "already exists" rather than 201. Looking
 * the existing one up keeps a dirty engine from failing every later test.
 */
export async function ensureSubnet(
  api: EngineApi,
  subnet: SubnetSpec,
  options: { shared?: boolean } = {}
): Promise<number> {
  const created = await api.addSubnetRaw({
    ip: subnet.ip,
    mask: subnet.mask,
    is_shared: options.shared ?? false,
  });

  if (created.status === 201) {
    return (created.body as { data: { id: number } }).data.id;
  }

  skipWhenHostCannotRoute(subnet, created.status, created.body);

  const existing = (await api.listSubnets()).data.find(
    (candidate) => candidate.ip === subnet.ip && candidate.mask === subnet.mask
  );

  if (!existing) {
    throw new Error(
      `Could not create subnet ${subnet.ip}/${subnet.mask} (status ${created.status}) ` +
        `and it is not in the listing either.`
    );
  }

  return existing.id;
}

/** Releases every address assigned out of `subnetId`, whoever holds it. */
export async function unassignAllFrom(api: EngineApi, subnetId: number): Promise<void> {
  const listing = await api.listAssignedIpsRaw();
  if (listing.status !== 200) {
    return;
  }

  const assignments = (
    listing.body as {
      data: { ip_subnet_id: number; username: string; ip_address: string }[];
    }
  ).data.filter((assignment) => assignment.ip_subnet_id === subnetId);

  for (const assignment of assignments) {
    await api.unassignIpRaw({
      username: assignment.username,
      ip_subnet_id: assignment.ip_subnet_id,
      ip_address: assignment.ip_address,
    });
  }
}

/** Drains a subnet and deletes it — the only order the engine accepts. */
export async function removeSubnet(api: EngineApi, subnetId: number): Promise<number> {
  await unassignAllFrom(api, subnetId);
  return (await api.deleteSubnetRaw(subnetId)).status;
}

/** An address inside IPV4_SUBNET, spread out to reduce collisions between tests. */
export function ipv4In(subnet: SubnetSpec = IPV4_SUBNET): string {
  const octets = subnet.ip.split('.').slice(0, 3).join('.');
  return `${octets}.${20 + Math.floor(Math.random() * 200)}`;
}

/** An address inside IPV6_SUBNET. */
export function ipv6In(subnet: SubnetSpec = IPV6_SUBNET): string {
  return `${subnet.ip}${(10 + Math.floor(Math.random() * 2000)).toString(16)}`;
}
