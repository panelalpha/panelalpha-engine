import type { APIRequestContext } from '@playwright/test';
import type { EngineApi } from '@/clients/engine-api';
import { delay, waitForCondition } from '@/helpers/retry';
import { redactAndTruncate } from '@/helpers/redact';
import { getWebserverInfo, getWebserverPropagationDelay } from '@/helpers/webserver-helpers';

/** Spacing between repeated HTTP probes while verifying a PHP version switch. */
export const HTTP_PROBE_SPACING_MS = 500;
/** Spacing between rapid back-to-back probes when checking for stale lsphp processes. */
export const RAPID_PROBE_SPACING_MS = 200;

/** A one-line script that prints the PHP version actually serving the request. */
export const PHP_VERSION_PROBE = '<?php echo "PHP_VERSION:" . PHP_VERSION; ?>';

/** `8.3` from `8.3`, `php8.3` or `8_3` — enough to match against `PHP_VERSION`. */
export function majorMinor(version: string): string {
  return /\d+\.\d+/.exec(version.replace(/[._]/g, '.'))?.[0] ?? version;
}

/** Fetches a probe script, bypassing any cache in front of it. */
export async function fetchProbe(
  http: APIRequestContext,
  domain: string,
  script: string
): Promise<string> {
  const response = await http.get(`https://${domain}/${script}?t=${Date.now()}`, {
    ignoreHTTPSErrors: true,
  });
  return response.ok() ? response.text() : '';
}

/**
 * Waits until the domain actually serves `version`.
 *
 * Setting the version through the API only rewrites configuration; the running
 * FPM or lsphp pool has to be recycled before requests land on the new binary,
 * and how long that takes depends on the webserver.
 */
export async function waitForServedPhpVersion(
  api: EngineApi,
  http: APIRequestContext,
  domain: string,
  script: string,
  version: string,
  budgetMs: number
): Promise<void> {
  const wanted = majorMinor(version);
  const { slug } = await getWebserverInfo(api);

  let lastBody = '';

  await waitForCondition(
    async () => {
      const body = await fetchProbe(http, domain, script);
      lastBody = body;
      return body.includes('PHP_VERSION:') && body.includes(wanted);
    },
    {
      timeout: budgetMs + getWebserverPropagationDelay(slug),
      interval: 2_000,
      describeLast: () => `probe body: ${redactAndTruncate(lastBody, 300)}`,
      message: `${domain} did not start serving PHP ${wanted} on ${script}`,
    }
  );
}

/** Probes `script` `times` times in a row, returning every body it saw. */
export async function probeRepeatedly(
  http: APIRequestContext,
  domain: string,
  script: string,
  times: number,
  spacingMs: number
): Promise<string[]> {
  const bodies: string[] = [];
  for (let i = 0; i < times; i++) {
    bodies.push(await fetchProbe(http, domain, script));
    await delay(spacingMs);
  }
  return bodies;
}
