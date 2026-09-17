import type { APIRequestContext, APIResponse } from '@playwright/test';
import { type EngineApi } from '@/clients/engine-api';
import type { ApiResponse, SystemChangeStatus, SystemInfo } from '@/types';
import { waitForCondition } from './retry';

const SYSTEM_INFO_RETRY_TIMEOUT_MS = 120_000;
const SYSTEM_INFO_RETRY_INTERVAL_MS = 3_000;

/**
 * Fetches GET /system/info with retries for transient failures (container restart, 502/503).
 */
export async function waitForSystemInfo(api: EngineApi): Promise<ApiResponse<SystemInfo>> {
  let lastError: Error | undefined;
  let response: ApiResponse<SystemInfo> | undefined;

  await waitForCondition(
    async () => {
      try {
        response = await api.getSystemInfo();
        return true;
      } catch (error) {
        lastError = error instanceof Error ? error : new Error(String(error));
        return false;
      }
    },
    {
      timeout: SYSTEM_INFO_RETRY_TIMEOUT_MS,
      interval: SYSTEM_INFO_RETRY_INTERVAL_MS,
      message: 'GET /system/info did not return 200',
    }
  );

  if (!response) {
    throw lastError ?? new Error('GET /system/info failed after retries');
  }

  return response;
}

/**
 * Extracts the HTTP scheme (http or https) from a fully-qualified URL.
 */
export function httpScheme(apiBaseUrl: string): string {
  return new URL(apiBaseUrl).protocol === 'http:' ? 'http' : 'https';
}

export const VALID_SLUGS = [
  'nginx',
  'nginx-proxy',
  'apache',
  'litespeed',
  'openlitespeed',
] as const;

/** A supported webserver slug, e.g. as accepted by `changeWebserver`. */
export type WebserverSlug = (typeof VALID_SLUGS)[number];

/** Main webserver config templates (global nginx/apache/litespeed wiring). */
export const TEMPLATE_MAP: Record<string, string[]> = {
  'nginx-proxy': ['webserver-nginx-proxy.blade.php', 'webserver-apache.blade.php'],
  nginx: ['webserver-nginx.blade.php'],
  apache: ['webserver-apache.blade.php'],
  litespeed: ['virtualHostConfig-litespeed.blade.php'],
  openlitespeed: ['virtualHostConfig-openlitespeed.blade.php'],
};

/** Per-user vhost templates where custom error page mappings are declared. */
export const VIRTUAL_HOST_TEMPLATE_MAP: Record<string, string[]> = {
  'nginx-proxy': ['virtualHost-nginx-proxy.blade.php', 'virtualHost-apache.blade.php'],
  nginx: ['virtualHost-nginx.blade.php'],
  apache: ['virtualHost-apache.blade.php'],
  litespeed: ['virtualHostConfig-litespeed.blade.php'],
  openlitespeed: ['virtualHostConfig-openlitespeed.blade.php'],
};

/** Nginx vhost templates that must ship an ACME HTTP-01 exception before force-HTTPS. */
export const ACME_CHALLENGE_VHOST_TEMPLATE_MAP: Record<string, string[]> = {
  'nginx-proxy': ['virtualHost-nginx-proxy.blade.php'],
  nginx: ['virtualHost-nginx.blade.php'],
};

export interface WebserverInfo {
  slug: string;
  raw: SystemInfo['webserver'];
}

export interface WebserverChangeSnapshot {
  latestWebserverChange: SystemChangeStatus | null;
}

/** Extract a normalised webserver slug from system/info (retries on transient engine errors). */
export async function getWebserverInfo(api: EngineApi): Promise<WebserverInfo> {
  const response = await waitForSystemInfo(api);
  const ws = response.data.webserver;
  let raw = '';
  if (typeof ws === 'string') {
    raw = ws;
  } else if (ws && typeof ws === 'object') {
    const wso = ws as { slug?: string; name?: string; type?: string };
    raw = wso.slug ?? wso.name ?? wso.type ?? '';
  }
  const slug = raw.toLowerCase().trim();
  return { slug, raw: ws };
}

export async function getWebserverChangeSnapshot(api: EngineApi): Promise<WebserverChangeSnapshot> {
  const response = await waitForSystemInfo(api);
  return {
    latestWebserverChange: response.data.latest_webserver_change ?? null,
  };
}

export function isNewerWebserverChange(
  candidate: SystemChangeStatus | null | undefined,
  previous: SystemChangeStatus | null | undefined
): boolean {
  if (!candidate?.started_at) {
    return false;
  }

  const previousStartedAt = previous?.started_at ?? 0;
  const previousFinishedAt = previous?.finished_at ?? 0;

  return (
    candidate.started_at > previousStartedAt ||
    (candidate.started_at === previousStartedAt &&
      (candidate.finished_at ?? 0) > previousFinishedAt)
  );
}

/**
 * True when a change appears in-flight after PUT /system/change-webserver, even if
 * started_at matches the pre-change snapshot (stale FPM read or symlink reuse).
 */
export function isWebserverChangeInFlight(
  change: SystemChangeStatus | null | undefined,
  initiatedAtMs: number
): boolean {
  if (!change?.started_at) {
    return false;
  }
  if (change.exit_code !== null && change.exit_code !== undefined) {
    return false;
  }
  const initiatedAtSec = Math.floor(initiatedAtMs / 1000);
  return change.started_at >= initiatedAtSec - 120;
}

export function formatWebserverChange(change: SystemChangeStatus | null | undefined): string {
  return JSON.stringify(change ?? {}, null, 2);
}

/** Resolve template file names for the given webserver slug. */
/** HTTP statuses that indicate the webserver stack is not ready yet. */
export function isHostingUnavailableStatus(status: number): boolean {
  return status === 0 || status === 502 || status === 503 || status === 504;
}

/**
 * Delay after rewrite/htaccess changes — derived from the active webserver slug.
 * Prefer this over TEST_ENV so rotation stays correct after change-webserver.
 */
export function getWebserverPropagationDelay(slug: string): number {
  const normalized = slug.toLowerCase();
  if (normalized.includes('openlitespeed')) {
    return 45_000;
  }
  if (normalized.includes('litespeed')) {
    return 8_000;
  }
  return 3_000;
}

/** Max time to wait for HTTPS after a full webserver restart (e.g. ModSec OWASP rule toggle). */
export function getWebserverRestartReadyMs(slug: string): number {
  const normalized = slug.toLowerCase();
  if (normalized.includes('openlitespeed')) {
    return 120_000;
  }
  if (normalized.includes('litespeed')) {
    return 60_000;
  }
  return 90_000;
}

/**
 * Max time to wait for the hosting stack to serve the setup site after a webserver
 * change (first container start: image pull + config/license + vhost regen + lsphp spawn).
 * Distinct from getWebserverPropagationDelay, which is for in-request rewrite/htaccess propagation.
 */
export function getWebserverFirstStartReadyMs(slug: string): number {
  const normalized = slug.toLowerCase();
  if (normalized.includes('openlitespeed')) {
    return 240_000;
  }
  if (normalized.includes('litespeed')) {
    return 180_000;
  }
  return 90_000;
}

/** Max time to poll for permalink/rewrite HTTP propagation. */
export function getWebserverRewriteWaitMs(slug: string): number {
  const normalized = slug.toLowerCase();
  if (normalized.includes('openlitespeed') || normalized.includes('litespeed')) {
    return 45_000;
  }
  return 15_000;
}

/**
 * Polls a site URL until it returns a non-gateway HTTP status.
 * Used after setup and webserver changes when vhosts need time to come up.
 */
export async function waitForSiteHttpReady(
  httpClient: APIRequestContext,
  siteUrl: string,
  options: { timeout?: number; interval?: number; requireSuccess?: boolean } = {}
): Promise<void> {
  const timeout = options.timeout ?? 90_000;
  const interval = options.interval ?? 2_000;
  const requireSuccess = options.requireSuccess ?? false;

  await waitForCondition(
    async () => {
      try {
        const response = await httpClient.get(siteUrl, {
          ignoreHTTPSErrors: true,
          timeout: 15_000,
          maxRedirects: 5,
        });
        const status = response.status();
        if (requireSuccess) {
          return status >= 200 && status < 400;
        }
        return !isHostingUnavailableStatus(status);
      } catch {
        return false;
      }
    },
    {
      timeout,
      interval,
      message: `Site did not become HTTP-ready within ${timeout}ms: ${siteUrl}`,
    }
  );
}

/**
 * Fetches a hosted site, reporting a transport failure as what it means.
 *
 * `APIRequestContext.get` throws on a connection error, so a site that is not
 * being served at all surfaces as `connect ECONNREFUSED <ip>:443` — the raw
 * transport error, with no hint that the webserver has stopped answering for
 * that address. On 2026-09-17 six specs failed that way at once from a single
 * cause, and each one had to be traced back by hand.
 *
 * Whatever the caller was asserting, "nothing is listening" is the more
 * important fact, so it is said plainly and the original error is kept as the
 * cause.
 */
export async function fetchSite(
  httpClient: APIRequestContext,
  siteUrl: string,
  options: Parameters<APIRequestContext['get']>[1] = {}
): Promise<APIResponse> {
  try {
    return await httpClient.get(siteUrl, { ignoreHTTPSErrors: true, ...options });
  } catch (error) {
    throw new Error(`${siteUrl} is not being served: ${describeTransportFailure(error)}`, {
      cause: error,
    });
  }
}

/** Turns a fetch failure into the thing an operator would check next. */
function describeTransportFailure(error: unknown): string {
  const message = error instanceof Error ? error.message : String(error);

  if (/ECONNREFUSED/i.test(message)) {
    return `nothing accepted the connection (${message.trim()}). The webserver is not listening on that address — check the vhost bind addresses and any NAT mapping.`;
  }
  if (/ENOTFOUND|EAI_AGAIN|getaddrinfo/i.test(message)) {
    return `the hostname does not resolve (${message.trim()}).`;
  }
  if (/ETIMEDOUT|timeout/i.test(message)) {
    return `the connection timed out (${message.trim()}). Something is listening but not answering, or a firewall is dropping the packets.`;
  }
  if (/ECONNRESET|EPIPE/i.test(message)) {
    return `the connection was reset (${message.trim()}).`;
  }
  return message.trim();
}

/** Webservers where OWASP CRS / ModSecurity HTTP blocking is expected to work. */
export function modSecurityHttpTestsApply(_slug: string): boolean {
  return true;
}

function resolveTemplateMap(slug: string, map: Record<string, string[]>): string[] | null {
  if (slug.includes('nginx') && slug.includes('apache')) {
    return map['nginx-proxy'];
  }
  if (slug.includes('nginx-proxy')) {
    return map['nginx-proxy'];
  }
  if (slug.includes('openlitespeed')) {
    return map.openlitespeed;
  }
  if (slug.includes('litespeed')) {
    return map.litespeed;
  }
  if (slug.includes('nginx')) {
    return map.nginx;
  }
  if (slug.includes('apache')) {
    return map.apache;
  }
  return null;
}

export function resolveTemplates(slug: string): string[] | null {
  return resolveTemplateMap(slug, TEMPLATE_MAP);
}

/** Vhost Blade templates that declare per-user custom error page paths. */
export function resolveVirtualHostTemplates(slug: string): string[] | null {
  return resolveTemplateMap(slug, VIRTUAL_HOST_TEMPLATE_MAP);
}

/** Nginx (and nginx-proxy) vhost templates that must allow ACME over plain HTTP. */
export function resolveAcmeChallengeVhostTemplates(slug: string): string[] | null {
  return resolveTemplateMap(slug, ACME_CHALLENGE_VHOST_TEMPLATE_MAP);
}

/** Engine wires force_https_redirect in vhost config only for nginx-proxy today. */
export function supportsForceHttpsRedirect(webserverSlug: string): boolean {
  return webserverSlug.includes('nginx-proxy');
}

/** True when TEST_ENV names a webserver that cannot enforce force_https_redirect. */
export function isForceHttpsRedirectUnsupportedEnv(): boolean {
  const envSlug = process.env.TEST_ENV?.trim().toLowerCase() ?? '';
  return Boolean(envSlug && envSlug !== 'nginx-proxy' && !envSlug.includes('nginx-proxy'));
}

/**
 * Whether an HTTP response is the webserver correctly refusing to serve a host
 * it does not know.
 *
 * Which shape that takes depends on the stack: nginx and Apache answer 404,
 * a vhost-less Apache answers 421 Misdirected Request, and LiteSpeed serves its
 * own branded "page not found" body with a 200.
 */
export function isUnknownHostResponse(status: number, body: string): boolean {
  return (
    status === 404 ||
    status === 421 ||
    (status === 200 && /page not found|misdirected request/i.test(body))
  );
}
