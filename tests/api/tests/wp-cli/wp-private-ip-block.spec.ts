import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { delay } from '@/helpers/retry';
import { fetchSite, httpScheme } from '@/helpers/webserver-helpers';
import {
  installLocalIpBlockMuPlugin,
  installWpHttpProbeScript,
  removeLocalIpBlockMuPlugin,
  removeWpHttpProbeScript,
  resolveEngineIpv4,
  wpRemoteGetStatusCode,
} from '@/helpers/wp-security-local-ip-helpers';
import { wpCliUnavailableReason, wpPath } from '@/helpers/wpcli-helpers';

const MU_PLUGIN_PROPAGATION_MS = 2_000;
const WORDFENCE_SLUG = 'wordfence';
const WORDFENCE_ACTIVATE_DELAY_MS = 5_000;

/**
 * Security plugins commonly refuse requests coming from private addresses. In a
 * containerised stack the webserver reaches the site over the internal network,
 * so such a plugin can block the site's own loopback traffic — WordPress cron,
 * the REST API and the update checks all break.
 *
 * These install a stand-in mu-plugin with that behaviour and check the engine
 * behaves the way a real one would, without depending on a third-party plugin.
 */
test.describe('private-IP blocking plugins', () => {
  test.beforeEach(async ({ api, setupUser }) => {
    const reason = await wpCliUnavailableReason(api, setupUser);
    test.skip(Boolean(reason), reason ?? '');

    await installWpHttpProbeScript(api, setupUser.username, setupUser.wpPath);
  });

  test.afterEach(async ({ api, setupUser }) => {
    await removeLocalIpBlockMuPlugin(api, setupUser.username, setupUser.wpPath).catch(
      () => undefined
    );
    await removeWpHttpProbeScript(api, setupUser.username, setupUser.wpPath).catch(() => undefined);
  });

  test('the homepage serves before anything is blocked', async ({
    anonymousRequest,
    setupUser,
  }) => {
    const response = await fetchSite(anonymousRequest, setupUser.url, {
      maxRedirects: 5,
      timeout: 30_000,
    });

    expect(response.status(), `${setupUser.url} is not serving`).toBeLessThan(400);
    expect(response.status()).toBeGreaterThanOrEqual(200);
    expect(response.status()).not.toBe(403);
  });

  test('an in-container wp_remote_get is refused while the block is on', async ({
    api,
    setupUser,
  }) => {
    await installLocalIpBlockMuPlugin(api, setupUser.username, setupUser.wpPath);
    await delay(MU_PLUGIN_PROPAGATION_MS);

    const result = await wpRemoteGetStatusCode(
      api,
      setupUser.username,
      setupUser.wpPath,
      setupUser.url
    );

    test.skip(
      result.exit_code !== 0 || result.status === null,
      `The in-container probe did not run (exit ${result.exit_code}): ${result.stderr}`
    );
    test.skip(
      result.status !== 403,
      `The container reached the site with status ${result.status}, so its source address ` +
        'is not private on this stack and the block cannot apply.'
    );

    expect(result.status).toBe(403);
  });

  test('removing the block restores in-container HTTP', async ({ api, setupUser }) => {
    await installLocalIpBlockMuPlugin(api, setupUser.username, setupUser.wpPath);
    await delay(MU_PLUGIN_PROPAGATION_MS);
    await removeLocalIpBlockMuPlugin(api, setupUser.username, setupUser.wpPath);
    await delay(MU_PLUGIN_PROPAGATION_MS);

    const result = await wpRemoteGetStatusCode(
      api,
      setupUser.username,
      setupUser.wpPath,
      setupUser.url
    );

    expect(result.exit_code, result.stderr).toBe(0);
    expect(result.status, 'the site is still refusing its own container').not.toBe(403);
    expect(result.status).toBeGreaterThanOrEqual(200);
    expect(result.status).toBeLessThan(400);
  });

  test('a request to the engine IP with the site Host header is refused too', async ({
    api,
    anonymousRequest,
    settings,
    setupUser,
  }) => {
    const engineIp = await resolveEngineIpv4(api);
    test.skip(!engineIp, 'system/info reports no default IPv4.');

    await installLocalIpBlockMuPlugin(api, setupUser.username, setupUser.wpPath);
    await delay(MU_PLUGIN_PROPAGATION_MS);

    const host = engineIp!.includes(':') ? `[${engineIp!}]` : engineIp!;
    const response = await fetchSite(
      anonymousRequest,
      `${httpScheme(settings.apiBaseUrl)}://${host}/`,
      {
        headers: { Host: setupUser.domain },
        maxRedirects: 0,
        timeout: 30_000,
      }
    );

    test.skip(
      response.status() !== 403,
      `The engine IP answered ${response.status()}, so its source address is not private ` +
        'on this stack — the in-container test remains the authoritative one.'
    );

    expect(await response.text()).toMatch(/forbidden|private\/local ip blocked/i);
  });

  /**
   * The same check against a real Wordfence install. Off by default because it
   * downloads and activates a large third-party plugin.
   */
  test('Wordfence does not lock the site out of itself', async ({ api, setupUser }) => {
    test.skip(
      process.env.WP_SECURITY_PLUGIN_TEST?.trim().toLowerCase() !== 'wordfence',
      'Set WP_SECURITY_PLUGIN_TEST=wordfence to run this.'
    );

    const install = await api.executeWpCliCommand(setupUser.username, [
      'plugin',
      'install',
      WORDFENCE_SLUG,
      '--activate',
      wpPath(setupUser),
    ]);
    // The run was asked for explicitly, so wp-cli failing is a result. Only a
    // failure to fetch the plugin — this host has no route to wordpress.org —
    // is environmental.
    if (install.exit_code !== 0) {
      const stderr = (install.stderr ?? '').toLowerCase();
      const unreachable = /could not resolve|connection|timed out|network|download failed|ssl/.test(
        stderr
      );
      expect(unreachable, `Wordfence install failed: ${install.stderr}`).toBe(true);
      test.skip(true, `Wordfence could not be downloaded on this host: ${install.stderr}`);
    }

    try {
      await delay(WORDFENCE_ACTIVATE_DELAY_MS);

      const result = await wpRemoteGetStatusCode(
        api,
        setupUser.username,
        setupUser.wpPath,
        setupUser.url
      );

      expect(result.exit_code, result.stderr).toBe(0);
      expectOneOf(result.status ?? 0, [200, 301, 302, 403]);
    } finally {
      await api
        .executeWpCliCommand(setupUser.username, [
          'plugin',
          'deactivate',
          WORDFENCE_SLUG,
          wpPath(setupUser),
        ])
        .catch(() => undefined);
    }
  });
});
