/** GET /firewall/status. `enabled` is null when the firewall could not be read; see `error`. */
export interface FirewallStatus {
  provider: string;
  enabled: boolean | null;
  version: string | null;
  default_incoming: string | null;
  default_outgoing: string | null;
  error: string | null;
}

export type FirewallAction = 'allow' | 'deny';
/** `both`: traffic from `source` coming in and to it going out, as one rule. */
export type FirewallDirection = 'in' | 'out' | 'both';
export type FirewallProtocol = 'tcp' | 'udp';

/**
 * Body of POST /firewall/rules. On PUT every field is optional: an unsent field
 * keeps its value and `null` clears it.
 */
export interface FirewallRuleRequest {
  action: FirewallAction;
  direction?: FirewallDirection | null;
  protocol?: FirewallProtocol | null;
  /** A port, a range (`30000:30009`) or a comma list. */
  port?: string | null;
  source?: string | null;
  destination?: string | null;
  comment?: string | null;
}

/** One entry of GET /firewall/logs, newest first. */
export interface FirewallLogEntry {
  time: string;
  type: 'blocked' | 'ban' | 'unban';
  /** Where a blocked connection came from (or, outbound, went to); who was banned. */
  address: string;
  direction: 'in' | 'out' | null;
  protocol: string | null;
  port: string | null;
  jail: string | null;
}

/** An address fail2ban never bans; not an allow rule. */
export interface TrustedAddress {
  /** Derived from the address. */
  id: string;
  address: string;
  comment: string | null;
}

export interface FirewallLogQuery {
  limit?: number;
  type?: FirewallLogEntry['type'];
  address?: string;
}

/**
 * A rule as every firewall endpoint returns it. A null field means "any".
 * Action, direction and protocol are open strings: a rule written on the host
 * can carry values the API itself never writes (`limit`, `esp`).
 */
export interface FirewallRule {
  /** Derived from what the rule matches, so it changes when the match does. */
  id: string;
  action: string;
  direction: string;
  protocol: string | null;
  port: string | null;
  source: string | null;
  destination: string | null;
  comment: string | null;
  /** Opened by the engine itself (comment starts `panelalpha:`); the API will not change it. */
  managed: boolean;
  editable: boolean;
  /** The host's own spelling, for a rule the API cannot edit. */
  raw: string | null;
}
