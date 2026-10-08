import type { APIRequestContext } from '@playwright/test';
import type { EngineApi } from '@/clients/engine-api';
import type { ModSecurityMode } from '@/types';
import { CleanupSteps } from './cleanup-step';
import { delay } from './retry';
import {
  getWebserverInfo,
  isHostingUnavailableStatus,
  waitForSiteHttpReady,
} from './webserver-helpers';

export interface ModsecHostingProbeResult {
  compatible: boolean;
  skipReason: string;
}

/** Per worker process: the verdict for each webserver and site host it was measured on. */
const probeCache = new Map<string, ModsecHostingProbeResult>();

/** What the probe leans on besides its arguments; a unit test replaces both. */
export interface ModsecProbeDeps {
  sleep: (ms: number) => Promise<void>;
  /** The engine's webserver slug, or '' when it cannot be read (nothing is cached then). */
  webserverSlug: (api: EngineApi) => Promise<string>;
}

const defaultDeps: ModsecProbeDeps = {
  sleep: delay,
  webserverSlug: async (api) => {
    try {
      return (await getWebserverInfo(api)).slug;
    } catch {
      return '';
    }
  },
};

/**
 * The verdict depends on the webserver build (the connector either matches it or not) and on
 * the site that was requested, so both are in the key. A switch of webserver in the same worker
 * therefore probes again instead of reusing the old answer.
 */
function probeCacheKey(slug: string, probeUrl: string): string {
  let host = probeUrl;
  try {
    host = new URL(probeUrl).host;
  } catch {
    // an unparsable URL keys on itself
  }
  return `${slug}|${host}`;
}

/** Puts the mode back to "off" after a failed probe, and reports it when that does not stick. */
async function restoreModsecModeOff(api: EngineApi, cleanup: CleanupSteps): Promise<void> {
  await cleanup.run(
    'set the ModSecurity mode back to off',
    () => api.setModSecurityConfig({ mode: 'off' }),
    {
      verify: async () => (await api.getModSecurityConfig()).data.mode === 'off',
      describe: () => 'the mode is not "off" after the hosting probe',
    }
  );
}

/**
 * Verifies nginx can serve HTTP with ModSecurity mode "on" (blocking).
 * Caches the result (positive or negative) for the worker process, per webserver and site host, — enabling modsec reloads nginx and
 * fails when the ngx_http_modsecurity_module.so build does not match nginx.
 * WAF scenarios enable mode "on", so detection_only is not a sufficient probe.
 */
export async function probeModsecurityHostingCompatible(
  api: EngineApi,
  httpClient: APIRequestContext,
  probeUrl: string,
  deps: ModsecProbeDeps = defaultDeps
): Promise<ModsecHostingProbeResult> {
  const slug = await deps.webserverSlug(api);
  const key = slug ? probeCacheKey(slug, probeUrl) : undefined;
  const known = key ? probeCache.get(key) : undefined;
  if (known) {
    return known;
  }

  const cleanup = new CleanupSteps();
  try {
    const result = await runModsecurityHostingProbe(api, httpClient, probeUrl, cleanup, deps);
    if (key) {
      probeCache.set(key, result);
    }
    return result;
  } catch (error) {
    cleanup.bodyFailed(error);
    throw error;
  } finally {
    await cleanup.finish();
  }
}

async function runModsecurityHostingProbe(
  api: EngineApi,
  httpClient: APIRequestContext,
  probeUrl: string,
  cleanup: CleanupSteps,
  deps: ModsecProbeDeps
): Promise<ModsecHostingProbeResult> {
  let initialMode: ModSecurityMode = 'off';
  try {
    initialMode = (await api.getModSecurityConfig()).data.mode;
  } catch {
    return {
      compatible: false,
      skipReason: 'ModSecurity probe failed: could not read current mode',
    };
  }

  try {
    await api.setModSecurityConfig({ mode: 'on' });
    // Kept: the nginx reload is not reported by the API, and an HTTP-ready poll would pass
    // against the old process. Runs once per worker (the result is cached).
    await deps.sleep(3_000);

    try {
      await waitForSiteHttpReady(httpClient, probeUrl, {
        timeout: 20_000,
        interval: 2_000,
      });
    } catch {
      await restoreModsecModeOff(api, cleanup);
      // Kept: no readable signal that the reload after restoring mode=off finished.
      await deps.sleep(2_000);
      return {
        compatible: false,
        skipReason:
          '[skip] nginx webserver unavailable after enabling ModSecurity (connector/nginx version mismatch on host)',
      };
    }

    await api.setModSecurityConfig({ mode: initialMode });
    // Kept: the check below must see the reloaded nginx, and nothing reports the reload.
    await deps.sleep(2_000);

    // Confirm hosting still works after restoring the initial mode.
    const check = await httpClient.get(probeUrl, {
      ignoreHTTPSErrors: true,
      timeout: 10_000,
    });
    if (isHostingUnavailableStatus(check.status())) {
      await restoreModsecModeOff(api, cleanup);
      return {
        compatible: false,
        skipReason:
          '[skip] nginx webserver unavailable after ModSecurity probe restore (left mode=off)',
      };
    }

    return { compatible: true, skipReason: '' };
  } catch (error) {
    await restoreModsecModeOff(api, cleanup);
    return {
      compatible: false,
      skipReason: `[skip] ModSecurity hosting probe failed: ${error instanceof Error ? error.message : String(error)}`,
    };
  }
}

/** Clears the cached verdicts (for targeted re-runs in the same worker, and for unit tests). */
export function resetModsecurityHostingProbeCache(): void {
  probeCache.clear();
}
