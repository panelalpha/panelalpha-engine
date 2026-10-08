import { expect, test } from '@/fixtures/test-options';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { modsecUnavailableReason } from '@/helpers/modsec-helpers';
import {
  OWASP_RULESET,
  REQUEST_898_CONFIG,
  REQUEST_933_CONFIG,
  REQUEST_942_CONFIG,
  REQUEST_949_CONFIG,
  TRACKED_CONFIG_FILES,
  configApplyTimeoutMs,
  getOwaspRuleset,
  isOwaspConfigFileEnabled,
  owaspConfigFilesMatch,
  owaspConfigFileExists,
  setOwaspConfigFiles,
  sqliProbeUrl,
  waitForSqliBlockState,
  waitForWooCommerceBlockState,
  wooCommerceAjaxUrl,
} from '@/helpers/modsec-config-apply';
import { waitForCondition } from '@/helpers/retry';
import {
  getWebserverInfo,
  getWebserverRestartReadyMs,
  waitForSiteHttpReady,
} from '@/helpers/webserver-helpers';
import type { ModSecurityMode } from '@/types';

/**
 * Enabling or disabling an individual CRS rule file has to change what the
 * running webserver actually does — not just what the API reports.
 *
 * Each test drives a real HTTP probe past the WAF, so every toggle costs a
 * webserver restart. That is why these have their own generous timeout.
 */
test.describe('OWASP config files apply to live traffic', () => {
  let siteUrl: string;
  let initialMode: ModSecurityMode;
  let owaspWasEnabled: boolean;
  let initialFileStates: Record<string, boolean>;
  let wooExclusionsAvailable: boolean;

  test.beforeEach(async ({ api, anonymousRequest, setupUser }) => {
    const { slug } = await getWebserverInfo(api);
    test.setTimeout(configApplyTimeoutMs(slug));

    const reason = await modsecUnavailableReason(api, anonymousRequest, setupUser.url);
    test.skip(Boolean(reason), reason ?? '');

    const owasp = await getOwaspRuleset(api);
    test.skip(!owasp, `The OWASP ruleset "${OWASP_RULESET}" is not installed.`);
    test.skip(
      !owaspConfigFileExists(owasp!.config_files, REQUEST_942_CONFIG) ||
        !owaspConfigFileExists(owasp!.config_files, REQUEST_949_CONFIG),
      'The CRS SQLi and blocking-evaluation rule files are missing.'
    );

    siteUrl = setupUser.url ?? `https://${setupUser.domain}/`;
    initialMode = (await api.getModSecurityConfig()).data.mode;
    owaspWasEnabled = owasp!.enabled;
    wooExclusionsAvailable = owaspConfigFileExists(owasp!.config_files, REQUEST_898_CONFIG);

    initialFileStates = Object.fromEntries(
      TRACKED_CONFIG_FILES.filter((name) => owaspConfigFileExists(owasp!.config_files, name)).map(
        (name) => [name, isOwaspConfigFileEnabled(owasp!.config_files, name)]
      )
    );

    if (!owasp!.enabled) {
      await api.enableModSecurityRuleset(OWASP_RULESET);
      await waitForCondition(async () => (await getOwaspRuleset(api))?.enabled === true, {
        timeout: 10_000,
        interval: 500,
        message: `${OWASP_RULESET} never reported as enabled`,
      });
      await waitForSiteHttpReady(anonymousRequest, siteUrl, {
        timeout: getWebserverRestartReadyMs(slug),
        interval: 2_000,
      });
    }

    await api.setModSecurityConfig({ mode: 'on' });
    await waitForCondition(async () => (await api.getModSecurityConfig()).data.mode === 'on', {
      timeout: 10_000,
      interval: 500,
      message: 'the ModSecurity mode never reported as "on"',
    });
    await waitForSiteHttpReady(anonymousRequest, siteUrl, {
      timeout: getWebserverRestartReadyMs(slug),
      interval: 2_000,
    });
  });

  test.afterEach(async ({ api, anonymousRequest }) => {
    // beforeEach can skip before it records anything, and Playwright still
    // runs this hook — dereferencing the unset snapshot would turn the skip
    // into a failure.
    if (!initialFileStates) {
      return;
    }

    const cleanup = new CleanupSteps();

    await cleanup.run(
      'restore the OWASP config files',
      () =>
        setOwaspConfigFiles(
          api,
          anonymousRequest,
          siteUrl,
          Object.entries(initialFileStates).map(([name, enabled]) => ({ name, enabled }))
        ),
      {
        verify: () => owaspConfigFilesMatch(api, initialFileStates),
        describe: () => 'a CRS config file is not in the state found before the test',
      }
    );

    if (!owaspWasEnabled) {
      await cleanup.run(
        'disable the OWASP ruleset again',
        () => api.disableModSecurityRuleset(OWASP_RULESET),
        {
          verify: async () => (await getOwaspRuleset(api))?.enabled === false,
          describe: () => `${OWASP_RULESET} is still enabled`,
        }
      );
    }
    await cleanup.run(
      'restore the ModSecurity mode',
      () => api.setModSecurityConfig({ mode: initialMode }),
      {
        verify: async () => (await api.getModSecurityConfig()).data.mode === initialMode,
        describe: () => `the mode is not back to "${initialMode}"`,
      }
    );
    // The mode restore above is verified through the API; what is left is the
    // reload reaching the webserver, which shows as the site serving again.
    await cleanup.run('wait for the site to serve again', async () => {
      const { slug } = await getWebserverInfo(api);
      await waitForSiteHttpReady(anonymousRequest, siteUrl, {
        timeout: getWebserverRestartReadyMs(slug),
        interval: 2_000,
      });
    });
    await cleanup.finish();
  });

  /**
   * REQUEST-949 is what converts an anomaly score into a 403. With it off, the
   * SQLi rules still match but nothing blocks — so toggling it must visibly
   * change whether an injection attempt gets through.
   *
   * The last step checks the site still serves after those toggles; it is the
   * only place that looks at the state the toggles leave behind.
   */
  test(
    'REQUEST-949 decides whether a SQLi probe is blocked',
    {
      tag: ['@security'],
    },
    async ({ api, anonymousRequest, setupUser }) => {
      const probeUrl = sqliProbeUrl(setupUser.domain);

      try {
        await setOwaspConfigFiles(api, anonymousRequest, siteUrl, [
          { name: REQUEST_942_CONFIG, enabled: true },
          { name: REQUEST_949_CONFIG, enabled: true },
        ]);
        await waitForSqliBlockState(anonymousRequest, probeUrl, true);

        await setOwaspConfigFiles(api, anonymousRequest, siteUrl, [
          { name: REQUEST_949_CONFIG, enabled: false },
        ]);
        await waitForSqliBlockState(anonymousRequest, probeUrl, false);

        // Back on again — a toggle that only works in one direction is still broken.
        await setOwaspConfigFiles(api, anonymousRequest, siteUrl, [
          { name: REQUEST_949_CONFIG, enabled: true },
        ]);
        await waitForSqliBlockState(anonymousRequest, probeUrl, true);
      } finally {
        await test.step('the site keeps serving through every toggle', async () => {
          // Runs even when a toggle failed; a failed request is recorded rather
          // than thrown so it cannot replace that earlier error.
          try {
            const response = await anonymousRequest.get(siteUrl, { ignoreHTTPSErrors: true });
            expect.soft(response.status(), 'the site stopped serving').toBeLessThan(500);
          } catch (error) {
            expect
              .soft(
                false,
                `the site did not answer: ${error instanceof Error ? error.message : String(error)}`
              )
              .toBe(true);
          }
        });
      }
    }
  );

  /**
   * WooCommerce's checkout AJAX posts `session_start();`, which the CRS PHP
   * injection rules match. REQUEST-898 carries the exception that lets it
   * through — without it, checkout breaks on every WooCommerce store.
   */
  test('the WooCommerce exception file decides whether checkout AJAX gets through', async ({
    api,
    anonymousRequest,
    setupUser,
  }) => {
    test.skip(!wooExclusionsAvailable, 'This CRS build ships no WooCommerce exception file.');

    const ajaxUrl = wooCommerceAjaxUrl(setupUser.domain);

    await test.step('with the exceptions enabled, the POST goes through', async () => {
      await setOwaspConfigFiles(api, anonymousRequest, siteUrl, [
        { name: REQUEST_933_CONFIG, enabled: true },
        { name: REQUEST_949_CONFIG, enabled: true },
        { name: REQUEST_898_CONFIG, enabled: true },
      ]);
      await waitForWooCommerceBlockState(anonymousRequest, ajaxUrl, false);
    });

    await test.step('with the exceptions disabled, the same POST is blocked', async () => {
      await setOwaspConfigFiles(api, anonymousRequest, siteUrl, [
        { name: REQUEST_898_CONFIG, enabled: false },
      ]);
      await waitForWooCommerceBlockState(anonymousRequest, ajaxUrl, true);
    });

    await test.step('re-enabling the exceptions unblocks it again', async () => {
      await setOwaspConfigFiles(api, anonymousRequest, siteUrl, [
        { name: REQUEST_898_CONFIG, enabled: true },
      ]);
      await waitForWooCommerceBlockState(anonymousRequest, ajaxUrl, false);
    });
  });
});
