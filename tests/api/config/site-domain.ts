import { lookup } from 'node:dns/promises';

const ENGINE_INFO_TIMEOUT_MS = 3_000;

const PANELALPHA_DIRECT = 'panelalpha.direct';
const PANELALPHA_ONLINE = 'panelalpha.online';

/** Third-party IP-to-name zones. The engine names projects under panelalpha.* instead. */
const THIRD_PARTY_IP_ZONES = ['sslip.io', 'nip.io'] as const;

export type HostLookup = (host: string, family: 4 | 6) => Promise<string>;

export type EngineSiteInfoLookup = (apiBaseUrl: string) => Promise<EngineSiteInfo | undefined>;

export interface EngineSiteInfo {
  defaultIpv4?: string;
  certDomain?: string;
  sitesBaseDomain?: string;
  url?: string;
  apiUrl?: string;
}

export interface ResolveSiteBaseDomainDeps {
  lookup?: HostLookup;
  fetchEngineInfo?: EngineSiteInfoLookup;
  onOverride?: (parent: string, resolvedFromApiUrl: string) => void;
}

export interface FetchEngineSiteInfoDeps {
  fetch: (
    input: string,
    init: { headers?: Record<string, string>; signal?: AbortSignal }
  ) => Promise<Response>;
  timeoutMs?: number;
}

export interface ResolvedSiteBase {
  parent: string;
  resolvedFromApiUrl?: string;
  engineIpv4?: string;
  apiUrl?: string;
}

function nonempty(value: string | null | undefined): string | undefined {
  const trimmed = value?.trim();
  if (!trimmed) {
    return undefined;
  }
  return trimmed;
}

/** True for IPv4 and IPv6 loopback — including the address Debian maps the hostname to. */
export function isLoopbackIp(ip: string | undefined): boolean {
  const trimmed = ip?.trim();
  if (!trimmed) {
    return false;
  }

  if (trimmed === '::1' || trimmed === '0:0:0:0:0:0:0:1') {
    return true;
  }

  const parts = trimmed.split('.');
  if (parts.length !== 4) {
    return false;
  }

  const octets = parts.map((part) => Number.parseInt(part, 10));
  if (octets.some((octet) => Number.isNaN(octet) || octet < 0 || octet > 255)) {
    return false;
  }

  return octets[0] === 127;
}

export function isIpLiteral(host: string): boolean {
  return /^\d{1,3}(\.\d{1,3}){3}$/.test(host) || host.includes(':');
}

/** `a.b.c.d` → `a-b-c-d.panelalpha.direct`, matching DomainPlan::directDomain. */
export function toPanelAlphaDirectZone(ip: string): string {
  return `${ip.replaceAll('.', '-')}.${PANELALPHA_DIRECT}`;
}

/**
 * The single label of a `*.panelalpha.online` name, or undefined for anything
 * else. Matches DomainPlan::onlineLabel.
 */
export function onlineLabel(domain: string): string | undefined {
  const normalized = domain.trim().replace(/\.+$/, '').toLowerCase();
  const suffix = `.${PANELALPHA_ONLINE}`;
  if (!normalized.endsWith(suffix)) {
    return undefined;
  }

  const label = normalized.slice(0, -suffix.length);
  return /^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/.test(label) ? label : undefined;
}

/**
 * Whether a `www.` alias for this name would answer.
 *
 * The WithoutDNS proxy serves the exact registered label and nothing below it,
 * so `www.<label>.panelalpha.online` is worse than no alias. Matches
 * DomainPlan::wwwAliasWouldAnswer.
 */
export function wwwAliasWouldAnswer(domain: string): boolean {
  return onlineLabel(domain) === undefined;
}

/**
 * Whether a generated project name resolves to the engine.
 *
 * Every rung of DomainPlan resolves somewhere, the `panelalpha.direct` one
 * included: its zone spells the address the name answers for, private address
 * or not, so the application is reachable wherever the engine is. Only the
 * `.local` last rung resolves nowhere. Matches DomainPlan's non-local rungs.
 */
export function projectNameResolves(domain: string): boolean {
  const normalized = domain.trim().replace(/\.+$/, '').toLowerCase();
  return !normalized.endsWith('.local');
}

/** Inverse of {@link toPanelAlphaDirectZone} for a zone parent, not a project FQDN. */
export function ipv4FromPanelAlphaDirectZone(domain: string): string | undefined {
  const match = /^(\d{1,3}(?:-\d{1,3}){3})\.panelalpha\.direct$/i.exec(domain.trim());
  if (!match) {
    return undefined;
  }

  const ip = match[1].replaceAll('-', '.');
  const parts = ip.split('.').map((part) => Number.parseInt(part, 10));
  if (parts.some((octet) => Number.isNaN(octet) || octet < 0 || octet > 255)) {
    return undefined;
  }

  return ip;
}

export function isThirdPartyIpZone(domain: string | undefined): boolean {
  const normalized = domain?.trim().toLowerCase();
  if (!normalized) {
    return false;
  }

  return THIRD_PARTY_IP_ZONES.some(
    (zone) => normalized === zone || normalized.endsWith(`.${zone}`)
  );
}

/**
 * Address test sites should resolve to.
 *
 * The engine serves vhosts on `default_ipv4`. DNS of API_BASE_URL is only a
 * hint — on the engine host itself a hostname typically maps to loopback in
 * /etc/hosts, and a proxy hostname maps to the balancer. Prefer the engine.
 */
export function pickSiteIpv4(options: {
  resolvedFromApiUrl?: string;
  engineDefaultIpv4?: string | null;
}): string | undefined {
  const engine = nonempty(options.engineDefaultIpv4);
  const resolved = nonempty(options.resolvedFromApiUrl);

  if (engine && (!resolved || isLoopbackIp(resolved) || resolved !== engine)) {
    return engine;
  }

  return resolved ?? engine;
}

/**
 * Parent addon / extra names hang off. Matches DomainPlan's non-online rungs:
 * an operator's own zone, else cert_domain, else `{dashed-ip}.panelalpha.direct`.
 * sslip.io / nip.io leftovers are ignored — those are not what the engine names.
 */
export function pickAddonParent(options: {
  explicit?: string | null;
  sitesBaseDomain?: string | null;
  certDomain?: string | null;
  defaultIpv4?: string | null;
}): string | undefined {
  const explicit = nonempty(options.explicit);
  if (explicit) {
    return isIpLiteral(explicit) && !explicit.includes(':')
      ? toPanelAlphaDirectZone(explicit)
      : explicit;
  }

  const sites = nonempty(options.sitesBaseDomain);
  if (sites && !isThirdPartyIpZone(sites)) {
    return sites;
  }

  const cert = nonempty(options.certDomain);
  if (cert && !isThirdPartyIpZone(cert)) {
    return cert;
  }

  const ip = nonempty(options.defaultIpv4);
  return ip ? toPanelAlphaDirectZone(ip) : undefined;
}

function stringField(record: Record<string, unknown>, key: string): string | undefined {
  const value = record[key];
  return typeof value === 'string' && value.trim() ? value.trim() : undefined;
}

function engineSiteInfoFromBody(body: unknown): EngineSiteInfo | undefined {
  if (typeof body !== 'object' || body === null || !('data' in body)) {
    return undefined;
  }

  const data = body.data;
  if (typeof data !== 'object' || data === null) {
    return undefined;
  }

  const record = data as Record<string, unknown>;
  const info: EngineSiteInfo = {
    defaultIpv4: stringField(record, 'default_ipv4'),
    certDomain: stringField(record, 'cert_domain'),
    sitesBaseDomain: stringField(record, 'sites_base_domain'),
    url: stringField(record, 'url'),
    apiUrl: stringField(record, 'api_url'),
  };

  return info.defaultIpv4 || info.certDomain || info.sitesBaseDomain || info.apiUrl || info.url
    ? info
    : undefined;
}

/** GET /system/info. Soft-fails: missing token or a down engine is not fatal. */
export async function fetchEngineSiteInfo(
  apiBaseUrl: string,
  token: string,
  deps: FetchEngineSiteInfoDeps
): Promise<EngineSiteInfo | undefined> {
  try {
    const response = await deps.fetch(`${apiBaseUrl}system/info`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      signal: AbortSignal.timeout(deps.timeoutMs ?? ENGINE_INFO_TIMEOUT_MS),
    });
    if (!response.ok) {
      return undefined;
    }
    return engineSiteInfoFromBody(await response.json());
  } catch {
    return undefined;
  }
}

async function resolveApiHostAddress(
  host: string,
  lookupFn: HostLookup
): Promise<string | undefined> {
  if (isIpLiteral(host)) {
    return host;
  }

  for (const family of [4, 6] as const) {
    try {
      return await lookupFn(host, family);
    } catch {
      // Try the other family; a host with neither record falls through.
    }
  }

  return undefined;
}

async function defaultLookup(host: string, family: 4 | 6): Promise<string> {
  return (await lookup(host, { family })).address;
}

/**
 * Parent generated extra domains hang off — the engine's own zone, not sslip.io.
 *
 * When API_BASE_URL is a hostname or loopback, ask the engine where it serves
 * sites so a box whose hostname maps to loopback still gets working DNS.
 */
export async function resolveSiteBaseDomain(
  apiBaseUrl: string,
  deps: ResolveSiteBaseDomainDeps = {}
): Promise<ResolvedSiteBase | undefined> {
  const host = new URL(apiBaseUrl).hostname.replace(/^\[|\]$/g, '');
  const lookupFn = deps.lookup ?? defaultLookup;
  const resolved = await resolveApiHostAddress(host, lookupFn);
  const info = await deps.fetchEngineInfo?.(apiBaseUrl);
  const siteIp = pickSiteIpv4({
    resolvedFromApiUrl: resolved,
    engineDefaultIpv4: info?.defaultIpv4,
  });
  const publicIp = siteIp && !isLoopbackIp(siteIp) ? siteIp : undefined;

  const parent = pickAddonParent({
    sitesBaseDomain: info?.sitesBaseDomain,
    certDomain: info?.certDomain,
    defaultIpv4: publicIp ?? info?.defaultIpv4,
  });

  if (!parent) {
    return undefined;
  }

  if (resolved && (isLoopbackIp(resolved) || (siteIp && siteIp !== resolved))) {
    deps.onOverride?.(parent, resolved);
  }

  return {
    parent,
    resolvedFromApiUrl: resolved,
    engineIpv4: siteIp,
    apiUrl: info?.apiUrl,
  };
}
