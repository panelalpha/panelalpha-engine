import { expect, test } from '@/fixtures/test-options';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { expectOneOf } from '@/helpers/expect-one-of';
import {
  INVALID_MODES,
  VALID_MODES,
  modsecUnavailableReason,
  snapshotModSec,
  type ModSecSnapshot,
} from '@/helpers/modsec-helpers';
import { waitForCondition } from '@/helpers/retry';
import { waitForSiteHttpReady } from '@/helpers/webserver-helpers';

/**
 * Mode is engine-wide: leaving it on `on` with an unexpected ruleset set would
 * change what every later HTTP test sees. Each mutating test restores the mode
 * it found. Invalid-mode tests do not touch the running config, so they skip
 * the restore delay.
 */
test.describe('ModSecurity mode', () => {
  let snapshot: ModSecSnapshot;
  let mutated = false;

  test.beforeEach(async ({ api, anonymousRequest, setupUser }) => {
    const reason = await modsecUnavailableReason(api, anonymousRequest, setupUser.url);
    test.skip(Boolean(reason), reason ?? '');

    snapshot = await snapshotModSec(api);
    mutated = false;
  });

  test.afterEach(async ({ api, anonymousRequest, setupUser }) => {
    // beforeEach can skip before it takes the snapshot, and Playwright still
    // runs this hook — dereferencing it would turn the skip into a failure.
    if (!snapshot || !mutated) {
      return;
    }

    const cleanup = new CleanupSteps();
    await cleanup.run(
      'restore the ModSecurity mode',
      () => api.setModSecurityConfig({ mode: snapshot.mode }),
      {
        verify: async () => (await api.getModSecurityConfig()).data.mode === snapshot.mode,
        describe: () => `the mode is not back to "${snapshot.mode}"`,
      }
    );
    // The restore is verified through the API; what is left is the reload
    // reaching the webserver, which shows as the site serving again.
    await cleanup.run('wait for the site to serve again', () =>
      waitForSiteHttpReady(anonymousRequest, setupUser.url ?? `https://${setupUser.domain}/`, {
        timeout: 30_000,
        interval: 1_000,
      })
    );
    await cleanup.finish();
  });

  test('the current mode is one of the three valid ones', { tag: ['@smoke'] }, async ({ api }) => {
    expectOneOf((await api.getModSecurityConfig()).data.mode, VALID_MODES);
  });

  test('each valid mode can be set and the site still serves', async ({
    api,
    anonymousRequest,
    setupUser,
  }) => {
    mutated = true;

    for (const mode of VALID_MODES) {
      await api.setModSecurityConfig({ mode });
      await waitForCondition(async () => (await api.getModSecurityConfig()).data.mode === mode, {
        timeout: 10_000,
        interval: 500,
        message: `the mode never reported as "${mode}"`,
      });
      await waitForSiteHttpReady(
        anonymousRequest,
        setupUser.url ?? `https://${setupUser.domain}/`,
        {
          timeout: 30_000,
          interval: 1_000,
        }
      );
      expect((await api.getModSecurityConfig()).data.mode).toBe(mode);
    }

    const response = await anonymousRequest.get(setupUser.url, { ignoreHTTPSErrors: true });
    expect(
      response.status(),
      'the webserver stopped serving after the mode was cycled'
    ).toBeLessThan(500);
  });

  /** Rapid switching used to leave the running config out of step with the API. */
  test('rapid successive mode changes land on the last one', async ({ api }) => {
    mutated = true;
    for (const mode of ['off', 'detection_only', 'on', 'off'] as const) {
      await api.setModSecurityConfig({ mode });
    }

    expect((await api.getModSecurityConfig()).data.mode).toBe('off');
  });

  test('invalid modes are refused', async ({ api }) => {
    for (const mode of INVALID_MODES) {
      await test.step(`refuse ${JSON.stringify(mode)}`, async () => {
        expectOneOf((await api.setModSecurityModeRaw(mode)).status, [400, 422]);
      });
    }

    await test.step('refuse a request with no mode', async () => {
      expectOneOf(
        (await api.setModSecurityModeRaw(undefined as unknown as string)).status,
        [400, 422]
      );
    });
  });
});
