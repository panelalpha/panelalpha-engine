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
import { parallelismFromEnv } from './config/parallelism';
import { API_SUITE_IGNORE, SERIAL_SPEC_MATCHERS } from './config/serial-specs';
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
// unless it is an sslip.io leftover. A hostname that locally maps to loopback
// is replaced with the engine's panelalpha.direct zone so bun run test does not
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

// Set by scripts/run-tests.ts when TEST_PARALLEL chose more than one worker; unset otherwise,
// and then nothing below differs from a single-worker run.
const parallel = parallelismFromEnv();
const split = parallel.apiWorkers > 1;
const phase = split ? parallel.phase : undefined;

const envSuffix = testEnv ? `-${testEnv}` : '';
const phaseSuffix = phase ? `-${phase}` : '';
const outputDir = path.resolve(rootDir, '.playwright', `test-results${envSuffix}${phaseSuffix}`);
const reportDir = path.resolve(rootDir, '.playwright', `report${envSuffix}${phaseSuffix}`);

/** Projects other than the parallel ones stay on one worker when the global limit is raised. */
const single = split ? { workers: 1 } : {};

const apiSuiteIgnore = [...API_SUITE_IGNORE];

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
  workers: phase === 'serial' ? 1 : parallel.apiWorkers,
  fullyParallel: false,
  retries: 0,
  forbidOnly: !!process.env.CI,

  reporter: [
    ['list'],
    // Prints what was skipped and why; fails the run when MAX_SKIPPED is set
    // and exceeded. A green run that executed half the suite is not a pass.
    // In a split run each phase only reports; run-tests.ts judges MAX_SKIPPED over both.
    phase
      ? ['./reporters/skip-budget.ts', { env: { ...process.env, MAX_SKIPPED: '999999999' } }]
      : ['./reporters/skip-budget.ts'],
    // Failed tests, kept projects, skips by category and the leak check, in one block
    // and in .playwright/summary.md. Reports only; it never changes the status.
    phase
      ? [
          './reporters/run-summary.ts',
          { dir: path.resolve(rootDir, '.playwright', `summary${phaseSuffix}`) },
        ]
      : ['./reporters/run-summary.ts'],
    ['html', { outputFolder: reportDir, open: 'never' }],
    ...(process.env.JUNIT_REPORT_FILE
      ? ([['junit', { outputFile: process.env.JUNIT_REPORT_FILE }]] as const)
      : []),
    // Last: traces record the Authorization header, and html copies them in its onEnd.
    ['./reporters/redact-secrets.ts', { dirs: [outputDir, reportDir] }],
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
      ...single,
      testMatch: /tests\/setup\/test-user\.setup\.ts/,
      timeout: Timeouts.setup,
    },
    {
      name: 'setup-dind',
      ...single,
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
      ...single,
      dependencies: ['setup'],
      testMatch: /.*\.spec\.ts/,
      // Host-wide disable/enable, full PHP matrix, DinD provision — tagged
      // @slow and run via `bun run test:slow`.
      grepInvert: /@slow/,
      testIgnore: apiSuiteIgnore,
    },
    ...(split
      ? [
          {
            // The API suite minus the specs in config/serial-specs.ts, one setup user per worker.
            // Files run on separate workers; the tests inside one file stay in order.
            name: 'api-parallel',
            dependencies: ['setup'],
            testMatch: /.*\.spec\.ts/,
            grepInvert: /@slow/,
            testIgnore: [...apiSuiteIgnore, ...SERIAL_SPEC_MATCHERS],
            workers: parallel.apiWorkers,
          },
          {
            // What config/serial-specs.ts lists, alone on one worker. The runner's second phase
            // reuses the setup users of the first, so it has no dependencies of its own there;
            // in a single invocation it waits for api-parallel so the two never overlap.
            name: 'api-serial',
            dependencies: phase === 'serial' ? [] : ['api-parallel'],
            testMatch: [...SERIAL_SPEC_MATCHERS],
            grepInvert: /@slow/,
            testIgnore: apiSuiteIgnore,
            workers: 1,
          },
        ]
      : []),
    {
      name: 'slow',
      ...single,
      dependencies: ['setup'],
      testMatch: /.*\.spec\.ts/,
      grep: /@slow/,
      testIgnore: apiSuiteIgnore,
    },
    {
      name: 'cli',
      ...single,
      testMatch: /tests\/cli\/.*\.spec\.ts/,
    },
    {
      // Real Supported-board apps, plus the checks that run once (staging,
      // backup, hook, a missing front page). Hours for the whole catalogue.
      // SUPPORTED_APPS=slug,slug narrows the catalogue only.
      name: 'supported-apps',
      // The catalogue file splits its tests over these workers; the checks file stays in order.
      ...(split ? { workers: parallel.appsWorkers } : {}),
      testMatch: /tests\/deploy\/supported-apps.*\.spec\.ts/,
      timeout: Timeouts.supportedApp,
    },
    {
      name: 'webserver-change',
      ...single,
      testMatch: /system\/webserver-change\.spec\.ts/,
      timeout: Timeouts.webserverChange + Timeouts.webserverChangePlaywrightBuffer,
    },
    {
      name: 'update',
      ...single,
      testMatch: /system\/update\/.*\.spec\.ts/,
    },
    {
      name: 'engine-cert',
      ...single,
      testMatch: /system\/engine-certificate\.spec\.ts/,
      timeout: Timeouts.deploy,
    },
    {
      name: 'network-mutation',
      ...single,
      testMatch: /system\/network-config-mutation\.spec\.ts/,
    },
  ],
});
