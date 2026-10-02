import { test } from '@playwright/test';
import type { EngineApi } from '@/clients/engine-api';
import { firewallRulesResponseSchema, firewallStatusResponseSchema } from '@/schemas';
import type { FirewallRule, FirewallStatus } from '@/types';

export const FIREWALL_UNAVAILABLE = 'The host firewall (ufw) is not installed or cannot be read.';

/** The ports the engine opens for itself, as managed rules. */
export const ENGINE_API_PORT = '2011';
export const ENGINE_SFTP_PORT = '2222';

/** Documentation ranges (RFC 5737): never routed, so a rule on them cannot lock anyone out. */
const TEST_NETS = ['192.0.2', '198.51.100', '203.0.113'] as const;

export async function readFirewallStatus(api: EngineApi): Promise<FirewallStatus> {
  return firewallStatusResponseSchema.parse(await api.getFirewallStatus()).data;
}

/**
 * Skips when the engine cannot read its firewall (`enabled: null`).
 *
 * The status endpoint answers either way, so an endpoint that fails is still a
 * failure; only a firewall the engine reports as absent is a skip.
 */
export async function requireFirewall(
  api: EngineApi
): Promise<FirewallStatus & { enabled: boolean }> {
  const status = await readFirewallStatus(api);
  test.skip(status.enabled === null, FIREWALL_UNAVAILABLE);
  return status as FirewallStatus & { enabled: boolean };
}

export async function listFirewallRules(api: EngineApi): Promise<FirewallRule[]> {
  return firewallRulesResponseSchema.parse(await api.listFirewallRules()).data;
}

export function randomTestNet(): (typeof TEST_NETS)[number] {
  return TEST_NETS[Math.floor(Math.random() * TEST_NETS.length)];
}

/** A single address in a documentation range. */
export function randomTestNetAddress(): string {
  return `${randomTestNet()}.${1 + Math.floor(Math.random() * 254)}`;
}

/** A high port nothing on the engine listens on; rules here only ever pair it with a TEST-NET source. */
export function randomTestPort(): string {
  return String(40_000 + Math.floor(Math.random() * 9_000));
}

/** Deletes rules a test created, ignoring the ones already gone. */
export async function deleteFirewallRulesQuietly(
  api: EngineApi,
  ids: Iterable<string>
): Promise<void> {
  for (const id of ids) {
    await api.deleteFirewallRuleRaw(id).catch(() => undefined);
  }
}

/** Puts the firewall back the way a test found it. */
export async function restoreFirewallState(api: EngineApi, enabled: boolean): Promise<void> {
  const status = await readFirewallStatus(api);
  if (status.enabled === null || status.enabled === enabled) {
    return;
  }
  await (enabled ? api.enableFirewall() : api.disableFirewall());
}
