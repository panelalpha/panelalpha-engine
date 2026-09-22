import { defineConfig } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { Timeouts } from './config/timeouts';
import {
  fetchEngineSiteInfo,
  isLoopbackIp,
  isThirdPartyIpZone,
  resolveSiteBaseDomain,
} from './config/site-domain';
import { insecureFetch } from './helpers/insecure-fetch';

const rootDir = path.dirname(fileURLToPath(import.meta.url));

/**
 * `env/.env` always loads first so API_BASE_URL / API_TOKEN / DOMAIN are set.
 * `TEST_ENV=nginx` then overlays `env/.env.nginx` on top of it.
 */
const testEnv = process.env.TEST_ENV?.trim();
dotenv.config({ path: path.resolve(rootDir, 'env/.env'), quiet: true });
if (testEnv) {
  dotenv.config({
    path: path.resolve(rootDir, `env/.env.${testEnv}`),
    override: true,
    quiet: true,
  });
}

/** The Engine API resolves relative paths, so the base URL must end in a slash. */
function normalizeBaseUrl(raw: string | undefined): string | undefined {
  const trimmed = raw?.trim();
  if (!trimmed) {
    return undefined;
  }
  try {
    const url = new URL(trimmed);
    if (!url.pathname.endsWith('/')) {
      url.pathname = `${url.pathname}/`;
    }
    return url.toString();
  } catch {
    return trimmed;
  }
}

let baseURL = normalizeBaseUrl(process.env.API_BASE_URL);
if (baseURL) {
  process.env.API_BASE_URL = baseURL;
}

// Resolving here, once, is what lets config/settings.ts stay synchronous —
// which every fixture and factory depends on. An explicit DOMAIN always wins
// unless it is an sslip.io leftover. A hostname that locally maps to 127.0.1.1
// is replaced with the engine's panelalpha.direct zone so npm test does not
// need DOMAIN set, and API_BASE_URL is rewritten to the engine's own api_url
// when the configured host only works on this box.
const configuredDomain = process.env.DOMAIN?.trim();
if (baseURL && (!configuredDomain || isThirdPartyIpZone(configuredDomain))) {
  const token = process.env.API_TOKEN?.trim();
  const derived = await resolveSiteBaseDomain(baseURL, {
    fetchEngineInfo: token
      ? (apiBaseUrl) => fetchEngineSiteInfo(apiBaseUrl, token, { fetch: insecureFetch })
      : undefined,
    onOverride: (parent, resolved) => {
      console.log(`Test sites will use ${parent} (engine zone; API host resolved to ${resolved})`);
    },
  });
  if (derived?.parent) {
    process.env.DOMAIN = derived.parent;
  }
  if (derived?.apiUrl && derived.resolvedFromApiUrl && isLoopbackIp(derived.resolvedFromApiUrl)) {
    const advertised = normalizeBaseUrl(derived.apiUrl);
    if (advertised) {
      baseURL = advertised;
      process.env.API_BASE_URL = advertised;
      console.log(`API_BASE_URL set to ${advertised} (engine APP_URL; was loopback)`);
    }
  }
}

const envSuffix = testEnv ? `-${testEnv}` : '';
const outputDir = path.resolve(rootDir, '.playwright', `test-results${envSuffix}`);
const reportDir = path.resolve(rootDir, '.playwright', `report${envSuffix}`);

const apiSuiteIgnore = [
  /tests\/unit\//,
  /tests\/cli\//,
  /tests\/deploy\//,
  /system\/webserver-change\.spec\.ts/,
  /system\/update\//,
  /system\/engine-certificate\.spec\.ts/,
  /system\/network-config-mutation\.spec\.ts/,
];

export default defineConfig({
  testDir: './tests',
  outputDir,
  timeout: Timeouts.default,
  expect: { timeout: Timeouts.expect },

  /**
   * The suite mutates one shared engine (users, domains, webserver config), so
   * parallelism is not safe here. Retries are off for the same reason: a retried
   * destructive test would replay against state its first attempt already changed.
   */
  workers: 1,
  fullyParallel: false,
  retries: 0,
  forbidOnly: !!process.env.CI,

  reporter: [
    ['list'],
    // Prints what was skipped and why; fails the run when MAX_SKIPPED is set
    // and exceeded. A green run that executed half the suite is not a pass.
    ['./reporters/skip-budget.ts'],
    ['html', { outputFolder: reportDir, open: 'never' }],
    ...(process.env.JUNIT_REPORT_FILE
      ? ([['junit', { outputFile: process.env.JUNIT_REPORT_FILE }]] as const)
      : []),
  ],

  use: {
    baseURL,
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    extraHTTPHeaders: {
      Accept: 'application/json',
      ...(process.env.API_TOKEN ? { Authorization: `Bearer ${process.env.API_TOKEN}` } : {}),
    },
  },

  projects: [
    {
      name: 'setup',
      testMatch: /tests\/setup\/test-user\.setup\.ts/,
      timeout: Timeouts.setup,
    },
    {
      name: 'setup-dind',
      testMatch: /tests\/setup\/dind-user\.setup\.ts/,
      timeout: Timeouts.deploy,
    },
    {
      // Pure logic — stub transports only, so it runs without an engine.
      // Nothing here touches the shared engine, so the suite-wide `workers: 1`
      // (which exists for that shared state) does not apply.
      name: 'unit',
      testMatch: /tests\/unit\/.*\.spec\.ts/,
      fullyParallel: true,
      workers: '50%',
    },
    {
      name: 'api',
      dependencies: ['setup'],
      testMatch: /.*\.spec\.ts/,
      // Host-wide disable/enable, full PHP matrix, DinD provision — tagged
      // @slow and run via `npm run test:slow`.
      grepInvert: /@slow/,
      testIgnore: apiSuiteIgnore,
    },
    {
      name: 'slow',
      dependencies: ['setup'],
      testMatch: /.*\.spec\.ts/,
      grep: /@slow/,
      testIgnore: apiSuiteIgnore,
    },
    {
      name: 'cli',
      testMatch: /tests\/cli\/.*\.spec\.ts/,
    },
    {
      // Real Supported-board apps, plus the checks that run once (staging,
      // backup, hook, a missing front page). Hours for the whole catalogue.
      // SUPPORTED_APPS=slug,slug narrows the catalogue only.
      name: 'supported-apps',
      testMatch: /tests\/deploy\/supported-apps.*\.spec\.ts/,
      timeout: Timeouts.supportedApp,
    },
    {
      name: 'webserver-change',
      testMatch: /system\/webserver-change\.spec\.ts/,
      timeout: Timeouts.webserverChange + Timeouts.webserverChangePlaywrightBuffer,
    },
    {
      name: 'update',
      testMatch: /system\/update\/.*\.spec\.ts/,
    },
    {
      name: 'engine-cert',
      testMatch: /system\/engine-certificate\.spec\.ts/,
      timeout: Timeouts.deploy,
    },
    {
      name: 'network-mutation',
      testMatch: /system\/network-config-mutation\.spec\.ts/,
    },
  ],
});
