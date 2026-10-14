import { test as base, expect } from '@playwright/test';

export type { APIRequestContext, APIResponse } from '@playwright/test';

import type { APIRequestContext } from '@playwright/test';
import { createApiTransport } from '@/clients/api-transport';
import { EngineApi } from '@/clients/engine-api';
import { getSettings, type Settings } from '@/config/settings';
import { DomainAssertions, SystemAssertions, UserAssertions } from '@/assertions';
import {
  CronFactory,
  DomainFactory,
  FtpFactory,
  MySqlFactory,
  UserFactory,
  WordPressFactory,
} from '@/test-data/factories';
import { manualCleanupPending } from '@/test-data/factories/user.factory';
import type { SetupTestData } from '@/types/user.types';
import {
  pickSetupUser,
  readSetupDindState,
  readSetupState,
  type SetupDindState,
} from './helper/setup-state';
import { workerCleanupInfo } from '@/helpers/cleanup-step';
import { expectStatus } from '@/helpers/expect-status';
import { withRequestMethods } from '@/helpers/request-method';
import { resolveHostExec, type HostExec } from '@/helpers/host-exec';
import { collectEngineContext } from '@/helpers/failure-context';
import { sharedProject, type SharedProject } from '@/helpers/shared-project';
import { openResources, readResourceEvents, resourceFile } from '@/helpers/resource-registry';

/**
 * The single import point for every spec in this suite.
 *
 * Specs import `test` and `expect` from here — never from `@playwright/test`
 * directly, which ESLint enforces — so all shared state arrives by dependency
 * injection and a fixture added here is immediately available everywhere.
 */
export interface EngineFixtures {
  /** Resolved configuration: base URL, test domain, timings, ports. */
  settings: Settings;
  /** Typed Engine API client, authenticated with the bearer token from env. */
  api: EngineApi;
  /** Authenticated raw request context, for asserting on status codes and raw bodies. */
  authedRequest: APIRequestContext;
  /** Unauthenticated context, for probing hosted sites and auth guards over plain HTTP. */
  anonymousRequest: APIRequestContext;

  userFactory: UserFactory;
  domainFactory: DomainFactory;
  wordpressFactory: WordPressFactory;
  ftpFactory: FtpFactory;
  mysqlFactory: MySqlFactory;
  cronFactory: CronFactory;

  userAssertions: UserAssertions;
  domainAssertions: DomainAssertions;
  systemAssertions: SystemAssertions;

  /**
   * The shared user provisioned once by the `setup` project, with WordPress installed.
   *
   * Tests that use it must leave it as they found it. Anything destructive should
   * create its own user through `userFactory` instead.
   */
  setupUser: SetupTestData;

  /**
   * Shared DinD project provisioned by the `setup-dind` project. Specs that
   * mutate it (stop, rebuild, cancel) must create their own user instead.
   */
  setupDindUser: SetupDindState;

  /** Host `pae-artisan` runner, or null when the wrapper is unreachable. */
  hostExec: HostExec | null;

  /** Set by `setupUser` so the failure context knows the shared project was in play. */
  contextUsage: { setupUser?: string };

  /**
   * Auto. After a failed test, attaches `engine-context.txt` (container logs, the setup
   * project record, laravel.log in the test window). Torn down before `userFactory`.
   */
  engineContext: void;
}

/**
 * Fails when a project recorded in a setup state file is gone or unreadable. A missing
 * state file is an environment precondition and skips; a project deleted mid-run is an error.
 */
async function requireSetupProject(
  api: EngineApi,
  username: string,
  label: string,
  setupProject: string
): Promise<void> {
  const response = await api.getUserRaw(username);
  if (response.status === 404) {
    throw new Error(
      `${label} "${username}" from the setup state no longer exists on the engine (GET projects/${username} → 404); it was deleted during the run — rerun ${setupProject}`
    );
  }
  await expectStatus(response, 200, `GET projects/${username} for ${label.toLowerCase()}`);
}

/** Worker-scoped fixtures: not part of what a spec asks for. */
interface WorkerFixtures {
  /** Deletes `autoCleanup: false` users that no test deleted by the time the worker ends. */
  manualUserSweep: void;
  /**
   * One empty DinD project per worker for tests whose every call on it is read-only or an
   * asserted refusal. Nothing is created until a test calls `get()`; the project is deleted
   * when the worker ends. `get()` resolves to null when the Engine refuses the DinD template
   * (keep the `skipUnless`) and rejects with the original error when provisioning failed.
   */
  sharedEmptyDind: SharedProject<'dind'>;
}

export const test = base.extend<EngineFixtures, WorkerFixtures>({
  manualUserSweep: [
    async ({ playwright }, use) => {
      await use();

      const leftover = manualCleanupPending();
      if (leftover.length === 0) {
        return;
      }
      const settings = getSettings();
      const context = await playwright.request.newContext({
        baseURL: settings.apiBaseUrl,
        ignoreHTTPSErrors: true,
        extraHTTPHeaders: {
          Accept: 'application/json',
          ...(process.env.API_TOKEN ? { Authorization: `Bearer ${process.env.API_TOKEN}` } : {}),
        },
      });
      try {
        const factory = new UserFactory(new EngineApi(createApiTransport(context)));
        const removed: string[] = [];
        const failed: string[] = [];
        for (const username of leftover) {
          await factory.deleteUser(username).then(
            () => removed.push(username),
            (error: unknown) => {
              failed.push(`${username}: ${error instanceof Error ? error.message : String(error)}`);
            }
          );
        }
        if (removed.length > 0) {
          console.log(`Deleted projects left by tests without cleanup: ${removed.join(', ')}`);
        }
        if (failed.length > 0) {
          throw new Error(
            `Projects left on the engine after the worker ended:\n${failed.join('\n')}`
          );
        }
      } finally {
        await context.dispose();
      }
    },
    { scope: 'worker', auto: true },
  ],

  sharedEmptyDind: [
    async ({}, use) => {
      const shared = sharedProject('dind');
      await use(shared);
      await shared.dispose(workerCleanupInfo());
    },
    { scope: 'worker' },
  ],

  settings: async ({}, use) => {
    await use(getSettings());
  },

  authedRequest: async ({ request }, use) => {
    await use(withRequestMethods(request));
  },

  anonymousRequest: async ({ playwright, settings }, use) => {
    // Isolated from the suite token in playwright.config.ts `use.extraHTTPHeaders`.
    const context = await playwright.request.newContext({
      baseURL: settings.apiBaseUrl,
      ignoreHTTPSErrors: true,
      extraHTTPHeaders: { Accept: 'application/json' },
    });
    await use(withRequestMethods(context));
    await context.dispose();
  },

  api: async ({ authedRequest }, use) => {
    await use(new EngineApi(createApiTransport(authedRequest)));
  },

  /**
   * Deletes every user the test created and did not delete itself.
   *
   * A failed test has its project record and deploy-log tail attached, then its users
   * are deleted too. Only PRESERVE_ON_FAILURE=1 outside CI keeps them (named in a
   * `preserved-users` annotation). Users made with `{ autoCleanup: false }` are the
   * test's to delete; the worker removes any it left. `{ preserveOnFailure: false }`
   * means never keep, whatever the environment says.
   */
  userFactory: async ({ api }, use, testInfo) => {
    const factory = new UserFactory(api);

    await use(factory);

    if (factory.pendingCleanup.length === 0) {
      return;
    }

    const failed = testInfo.status !== testInfo.expectedStatus;
    const { kept, errors } = await factory.settle(failed);
    if (kept.length > 0) {
      testInfo.annotations.push({
        type: 'preserved-users',
        // Names only: the run summary adds how to inspect and delete them.
        description: kept.join(', '),
      });
    }
    if (errors.length > 0) {
      throw new Error(errors.join('\n'));
    }
  },

  domainFactory: async ({ api }, use) => {
    await use(new DomainFactory(api));
  },

  wordpressFactory: async ({ api }, use) => {
    await use(new WordPressFactory(api));
  },

  ftpFactory: async ({ api }, use) => {
    await use(new FtpFactory(api));
  },

  mysqlFactory: async ({ api }, use) => {
    await use(new MySqlFactory(api));
  },

  cronFactory: async ({ api }, use) => {
    await use(new CronFactory(api));
  },

  userAssertions: async ({ api }, use) => {
    await use(new UserAssertions(api));
  },

  domainAssertions: async ({ api }, use) => {
    await use(new DomainAssertions(api));
  },

  systemAssertions: async ({ api }, use) => {
    await use(new SystemAssertions(api));
  },

  contextUsage: async ({}, use) => {
    await use({});
  },

  /**
   * Depends on `userFactory` and `api`, so it is set up after them and Playwright tears it
   * down first: the factory still has its pending projects and the Engine still has them
   * when this reads. The host runner is resolved only on failure, so passing tests (and
   * unit specs) never probe the host.
   */
  engineContext: [
    async ({ api, userFactory, contextUsage }, use, testInfo) => {
      const startedAt = Date.now();
      await use();

      if (testInfo.status === 'skipped' || testInfo.status === testInfo.expectedStatus) {
        return;
      }
      try {
        const title = testInfo.titlePath.filter((part) => part !== '').join(' > ');
        const registered = openResources(
          readResourceEvents(resourceFile()).filter((event) => event.test === title)
        );
        const projects = [...new Set([...userFactory.pendingCleanup, ...registered])];
        const hostExec = await resolveHostExec().catch(() => null);
        const body = await collectEngineContext({
          api,
          hostExec,
          projects,
          setupUser: contextUsage.setupUser,
          window: { fromMs: startedAt, toMs: Date.now() },
        });
        await testInfo.attach('engine-context.txt', { body, contentType: 'text/plain' });
        testInfo.annotations.push({
          type: 'engine-context',
          description: 'see the engine-context.txt attachment',
        });
      } catch {
        // Diagnostics must never replace the test error.
      }
    },
    { auto: true },
  ],

  setupUser: async ({ api, contextUsage }, use, testInfo) => {
    const state = readSetupState();
    if (!state) {
      test.skip(true, 'No setup state — run `bun run test:setup` first.');
      return;
    }

    // One user per worker in a split run; a single-worker run has just the one.
    const user = pickSetupUser(state, testInfo.parallelIndex);
    await requireSetupProject(api, user.username, 'Setup user', 'setup');
    contextUsage.setupUser = user.username;

    await use(user);
  },

  setupDindUser: async ({ api }, use) => {
    const state = readSetupDindState();
    if (!state) {
      test.skip(
        true,
        'No DinD setup state — run `bunx playwright test --project=setup-dind` first.'
      );
      return;
    }

    await requireSetupProject(api, state.username, 'Setup DinD user', 'setup-dind');

    await use(state);
  },

  hostExec: async ({}, use) => {
    await use(await resolveHostExec());
  },
});

export { expect };
