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
import type { SetupTestData } from '@/types/user.types';
import { readSetupDindState, readSetupState, type SetupDindState } from './helper/setup-state';
import { resolveHostExec, type HostExec } from '@/helpers/host-exec';

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
}

export const test = base.extend<EngineFixtures>({
  settings: async ({}, use) => {
    await use(getSettings());
  },

  authedRequest: async ({ request }, use) => {
    await use(request);
  },

  anonymousRequest: async ({ playwright, settings }, use) => {
    // Isolated from the suite token in playwright.config.ts `use.extraHTTPHeaders`.
    const context = await playwright.request.newContext({
      baseURL: settings.apiBaseUrl,
      ignoreHTTPSErrors: true,
      extraHTTPHeaders: { Accept: 'application/json' },
    });
    await use(context);
    await context.dispose();
  },

  api: async ({ authedRequest }, use) => {
    await use(new EngineApi(createApiTransport(authedRequest)));
  },

  /**
   * Deletes every user the test created and did not delete itself.
   *
   * A failed test keeps its users so the engine state can be inspected; their
   * names land in the report as a `preserved-users` annotation. Pass
   * `{ autoCleanup: false }` when creating a user that must outlive one test,
   * or `{ preserveOnFailure: false }` to delete even after a failure (Online
   * names).
   */
  userFactory: async ({ api }, use, testInfo) => {
    const factory = new UserFactory(api);

    await use(factory);

    const pending = factory.pendingCleanup;
    if (pending.length === 0) {
      return;
    }

    const failed = testInfo.status !== testInfo.expectedStatus;
    const keep: string[] = [];
    const drop: string[] = [];
    for (const username of pending) {
      if (failed && factory.shouldPreserveOnFailure(username)) {
        keep.push(username);
      } else {
        drop.push(username);
      }
    }
    if (keep.length > 0) {
      testInfo.annotations.push({ type: 'preserved-users', description: keep.join(', ') });
    }
    for (const username of drop) {
      await factory.deleteUser(username);
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

  setupUser: async ({ api }, use) => {
    const state = readSetupState();
    if (!state) {
      test.skip(true, 'No setup state — run `npm run test:setup` first.');
      return;
    }

    const { status } = await api.getUserRaw(state.user.username);
    if (status === 404) {
      test.skip(
        true,
        `Setup user "${state.user.username}" no longer exists on the engine — rerun setup.`
      );
      return;
    }

    await use(state.user);
  },

  setupDindUser: async ({ api }, use) => {
    const state = readSetupDindState();
    if (!state) {
      test.skip(
        true,
        'No DinD setup state — run `npx playwright test --project=setup-dind` first.'
      );
      return;
    }

    const { status } = await api.getUserRaw(state.username);
    if (status === 404) {
      test.skip(
        true,
        `Setup DinD user "${state.username}" no longer exists on the engine — rerun setup-dind.`
      );
      return;
    }

    await use(state);
  },

  hostExec: async ({}, use) => {
    await use(await resolveHostExec());
  },
});

export { expect };
