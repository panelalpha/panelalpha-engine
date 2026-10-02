import { z } from 'zod';

const nullableString = z.string().nullable();

/** GET /firewall/status. `enabled` is null when the firewall could not be read. */
export const firewallStatusSchema = z.strictObject({
  provider: z.string().min(1),
  enabled: z.boolean().nullable(),
  version: nullableString,
  default_incoming: nullableString,
  default_outgoing: nullableString,
  error: nullableString,
});

export const firewallStatusResponseSchema = z.object({ data: firewallStatusSchema });

/**
 * One rule, as list, create, update and delete all return it.
 *
 * Action, direction and protocol stay open strings: a rule written on the host
 * (`ufw limit`, `proto esp`) is listed as it is, marked not editable.
 */
export const firewallRuleSchema = z.strictObject({
  id: z.string().regex(/^[0-9a-f]{12}$/),
  action: z.string().min(1),
  direction: z.string().min(1),
  protocol: nullableString,
  port: nullableString,
  source: nullableString,
  destination: nullableString,
  comment: nullableString,
  managed: z.boolean(),
  editable: z.boolean(),
  raw: nullableString,
});

export const firewallRuleResponseSchema = z.object({ data: firewallRuleSchema });

export const firewallRulesResponseSchema = z.object({ data: z.array(firewallRuleSchema) });

/** One entry of GET /firewall/logs: a blocked connection, or a fail2ban ban or release. */
export const firewallLogEntrySchema = z.strictObject({
  time: z.iso.datetime({ offset: true }),
  type: z.enum(['blocked', 'ban', 'unban']),
  address: z.string().min(1),
  direction: z.enum(['in', 'out']).nullable(),
  protocol: nullableString,
  port: nullableString,
  jail: nullableString,
});

export const firewallLogsResponseSchema = z.object({ data: z.array(firewallLogEntrySchema) });

/** An address fail2ban never bans. */
export const trustedAddressSchema = z.strictObject({
  id: z.string().regex(/^[0-9a-f]{12}$/),
  address: z.string().min(1),
  comment: nullableString,
});

export const trustedAddressResponseSchema = z.object({ data: trustedAddressSchema });

export const trustedAddressesResponseSchema = z.object({ data: z.array(trustedAddressSchema) });
