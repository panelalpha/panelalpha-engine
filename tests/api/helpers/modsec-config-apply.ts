import type { APIRequestContext } from '@playwright/test';
import type { EngineApi } from '@/clients/engine-api';
import { isBlockedResponse } from '@/helpers/modsec-helpers';
import { delay, waitForCondition } from '@/helpers/retry';
import {
  getWebserverInfo,
  getWebserverPropagationDelay,
  getWebserverRestartReadyMs,
  waitForSiteHttpReady,
} from '@/helpers/webserver-helpers';

export const OWASP_RULESET = 'owasp-crs';

/** The CRS rule that turns accumulated anomaly scores into an actual block. */
export const REQUEST_949_CONFIG = 'REQUEST-949-BLOCKING-EVALUATION.conf';
export const REQUEST_942_CONFIG = 'REQUEST-942-APPLICATION-ATTACK-SQLI.conf';
export const REQUEST_933_CONFIG = 'REQUEST-933-APPLICATION-ATTACK-PHP.conf';
/** Exceptions that keep WooCommerce's AJAX from tripping the PHP-injection rules. */
export const REQUEST_898_CONFIG = 'REQUEST-898-WORDPRESS-WOOCOMMERCE-EXCEPTIONS.conf';

export const TRACKED_CONFIG_FILES = [
  REQUEST_942_CONFIG,
  REQUEST_933_CONFIG,
  REQUEST_949_CONFIG,
  REQUEST_898_CONFIG,
] as const;

const SQLI_PROBE_QUERY = "1' or '1'='1";
const WOOCOMMERCE_AJAX_ACTION = 'update_order_review';
/** Looks like PHP injection to CRS, but is what WooCommerce genuinely posts. */
const WOOCOMMERCE_TRIGGER_POST_DATA = 'session_start();';

export const POLL_TIMEOUT_MS = 90_000;
export const POLL_INTERVAL_MS = 2_000;

/**
 * A per-test budget wide enough for the webserver restarts this suite triggers.
 *
 * Toggling a CRS config file restarts the webserver, and OpenLiteSpeed takes the
 * best part of a minute to come back; six restarts plus three WAF poll rounds is
 * the worst case here.
 */
export function configApplyTimeoutMs(webserverSlug: string): number {
  const slug = webserverSlug.toLowerCase();
  const propagationMs = getWebserverPropagationDelay(slug);
  const restartMs = getWebserverRestartReadyMs(slug);

  return Math.max(300_000, 7 * propagationMs + 6 * restartMs + 3 * POLL_TIMEOUT_MS + 120_000);
}

export function sqliProbeUrl(domain: string): string {
  return `https://${domain}/?${new URLSearchParams({ id: SQLI_PROBE_QUERY }).toString()}`;
}

export function wooCommerceAjaxUrl(domain: string): string {
  return `https://${domain}/?wc-ajax=${WOOCOMMERCE_AJAX_ACTION}`;
}

type ConfigFileEntry = string | { file: string };

/** A disabled config file is listed with a `.disabled` suffix, so both count as present. */
export function owaspConfigFileExists(
  configFiles: ConfigFileEntry[] | undefined,
  name: string
): boolean {
  const names = configFileNames(configFiles);
  return names.includes(name) || names.includes(`${name}.disabled`);
}

export function isOwaspConfigFileEnabled(
  configFiles: ConfigFileEntry[] | undefined,
  name: string
): boolean {
  return configFileNames(configFiles).includes(name);
}

function configFileNames(configFiles: ConfigFileEntry[] | undefined): string[] {
  return (configFiles ?? []).map((entry) => (typeof entry === 'string' ? entry : entry.file));
}

export async function getOwaspRuleset(api: EngineApi) {
  return (await api.listModSecurityRulesets()).data.find(
    (ruleset) => ruleset.name === OWASP_RULESET
  );
}

/** Whether the OWASP ruleset's config files are currently in the given states. */
export async function owaspConfigFilesMatch(
  api: EngineApi,
  expected: Record<string, boolean>
): Promise<boolean> {
  const owasp = await getOwaspRuleset(api);
  if (!owasp) {
    return false;
  }
  return Object.entries(expected).every(
    ([name, enabled]) => isOwaspConfigFileEnabled(owasp.config_files, name) === enabled
  );
}

/**
 * Brings the named CRS config files to the requested states and waits until the
 * webserver is serving again.
 *
 * Files already in the wanted state are skipped, because each toggle costs a
 * restart.
 */
export async function setOwaspConfigFiles(
  api: EngineApi,
  http: APIRequestContext,
  siteUrl: string,
  changes: { name: string; enabled: boolean }[]
): Promise<void> {
  const owasp = await getOwaspRuleset(api);
  if (!owasp) {
    throw new Error(`The OWASP ruleset "${OWASP_RULESET}" is not installed.`);
  }

  const toEnable: string[] = [];
  const toDisable: string[] = [];

  for (const { name, enabled } of changes) {
    if (isOwaspConfigFileEnabled(owasp.config_files, name) === enabled) {
      continue;
    }
    (enabled ? toEnable : toDisable).push(name);
  }

  if (toEnable.length === 0 && toDisable.length === 0) {
    return;
  }

  await api.toggleModSecurityConfigFiles(OWASP_RULESET, toEnable, toDisable);

  const { slug } = await getWebserverInfo(api);
  await waitForSiteHttpReady(http, siteUrl, {
    timeout: getWebserverRestartReadyMs(slug),
    interval: POLL_INTERVAL_MS,
  });
  await delay(getWebserverPropagationDelay(slug));
}

interface Probe {
  status: number;
  body: string;
}

async function probe(request: () => Promise<Probe>): Promise<Probe> {
  try {
    return await request();
  } catch {
    // A connection error mid-restart is not an answer; treat it as "no result".
    return { status: 0, body: '' };
  }
}

/** Waits until a SQL-injection probe is (or stops being) blocked. */
export async function waitForSqliBlockState(
  http: APIRequestContext,
  url: string,
  shouldBlock: boolean
): Promise<void> {
  await waitForBlockState(
    () => probe(async () => readResponse(await http.get(url, httpOptions))),
    shouldBlock,
    shouldBlock ? `${url} was never blocked` : `${url} stayed blocked`
  );
}

/** Waits until the WooCommerce AJAX POST is (or stops being) blocked. */
export async function waitForWooCommerceBlockState(
  http: APIRequestContext,
  url: string,
  shouldBlock: boolean
): Promise<void> {
  await waitForBlockState(
    () =>
      probe(async () =>
        readResponse(
          await http.post(url, {
            form: {
              'wc-ajax': WOOCOMMERCE_AJAX_ACTION,
              post_data: WOOCOMMERCE_TRIGGER_POST_DATA,
              security: 'panelalpha-test',
            },
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            ...httpOptions,
          })
        )
      ),
    shouldBlock,
    shouldBlock
      ? 'the WooCommerce AJAX POST was never blocked'
      : 'the WooCommerce AJAX POST stayed blocked'
  );
}

const httpOptions = { ignoreHTTPSErrors: true, timeout: 15_000 } as const;

async function readResponse(response: {
  status: () => number;
  text: () => Promise<string>;
}): Promise<Probe> {
  return { status: response.status(), body: await response.text() };
}

async function waitForBlockState(
  send: () => Promise<Probe>,
  shouldBlock: boolean,
  message: string
): Promise<void> {
  let last: Probe = { status: 0, body: '' };

  await waitForCondition(
    async () => {
      last = await send();
      return isBlockedResponse(last.status, last.body) === shouldBlock;
    },
    {
      timeout: POLL_TIMEOUT_MS,
      interval: POLL_INTERVAL_MS,
      message,
      describeLast: () =>
        Promise.resolve(
          `status=${last.status}, blocked=${isBlockedResponse(last.status, last.body)}`
        ),
    }
  );
}
