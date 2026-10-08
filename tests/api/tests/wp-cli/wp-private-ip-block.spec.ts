import { expect, test } from '@/fixtures/test-options';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { expectOneOf } from '@/helpers/expect-one-of';
import { isPrivateOrLocalIp } from '@/helpers/real-ip-helpers';
import { delay } from '@/helpers/retry';
import { fetchSite, httpScheme } from '@/helpers/webserver-helpers';
import {
  REMOTE_ADDR_HEADER,
  describeChain,
  followViaEngineIp,
  installLocalIpBlockMuPlugin,
  installWpHttpProbeScript,
  removeLocalIpBlockMuPlugin,
  removeWpHttpProbeScript,
  resolveEngineIpv4,
  wpRemoteGetChain,
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
    const cleanup = new CleanupSteps();
    await cleanup.run('remove the private-IP block mu-plugin', () =>
      removeLocalIpBlockMuPlugin(api, setupUser.username, setupUser.wpPath)
    );
    await cleanup.run('remove the HTTP probe script', () =>
      removeWpHttpProbeScript(api, setupUser.username, setupUser.wpPath)
    );
    await cleanup.finish();
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

    // A probe or setup failure throws here and fails the test: it is not a skip.
    const chain = await wpRemoteGetChain(api, setupUser.username, setupUser.wpPath, setupUser.url);
    const path = describeChain(chain.hops);

    expect(
      chain.remoteAddr,
      `the block mu-plugin never answered, so the request did not reach PHP with it active: ${path}`
    ).not.toBeNull();

    // The only environmental condition: the container's source address on this stack.
    test.skip(
      !isPrivateOrLocalIp(chain.remoteAddr),
      `The in-container request reached the site as REMOTE_ADDR ${chain.remoteAddr}, which is not ` +
        'a private address on this stack, so the block cannot apply.'
    );

    // eslint-disable-next-line local/no-bare-status-assertion -- status parsed from an in-container probe, not an API response
    expect(chain.final.status, `a private REMOTE_ADDR was not blocked: ${path}`).toBe(403);
  });

  test('removing the block restores in-container HTTP', async ({ api, setupUser }) => {
    await installLocalIpBlockMuPlugin(api, setupUser.username, setupUser.wpPath);
    await delay(MU_PLUGIN_PROPAGATION_MS);
    await removeLocalIpBlockMuPlugin(api, setupUser.username, setupUser.wpPath);
    await delay(MU_PLUGIN_PROPAGATION_MS);

    const chain = await wpRemoteGetChain(api, setupUser.username, setupUser.wpPath, setupUser.url);
    const path = describeChain(chain.hops);

    expect(chain.remoteAddr, `the removed mu-plugin is still answering: ${path}`).toBeNull();
    // eslint-disable-next-line local/no-bare-status-assertion -- status parsed from an in-container probe, not an API response
    expect(chain.final.status, `the site is still refusing its own container: ${path}`).toBe(200);
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
    const chain = await followViaEngineIp(
      async (url) => {
        const response = await fetchSite(anonymousRequest, url, {
          headers: { Host: setupUser.domain },
          maxRedirects: 0,
          timeout: 30_000,
        });
        return {
          status: response.status(),
          location: response.headers().location ?? null,
          remoteAddr: response.headers()[REMOTE_ADDR_HEADER] ?? null,
          body: await response.text(),
        };
      },
      httpScheme(settings.apiBaseUrl),
      host,
      setupUser.domain
    );
    const path = describeChain(chain.hops);

    // A 301 is the webserver's force-HTTPS redirect, answered before PHP: it says
    // nothing about the source address, so the chain is followed until PHP answers.
    expect(
      chain.remoteAddr,
      `the engine address never reached PHP with the block mu-plugin active: ${path}`
    ).not.toBeNull();

    test.skip(
      !isPrivateOrLocalIp(chain.remoteAddr),
      `The engine IP answered ${chain.final.status}, so its source address is not private ` +
        `on this stack (REMOTE_ADDR ${chain.remoteAddr}) - the in-container test remains the authoritative one.`
    );

    // eslint-disable-next-line local/no-bare-status-assertion -- chain of raw requests to the engine address, described in the message
    expect(chain.final.status, `a private REMOTE_ADDR was not blocked: ${path}`).toBe(403);
    expect(chain.final.body).toMatch(/forbidden|private\/local ip blocked/i);
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

    const cleanup = new CleanupSteps();
    try {
      await delay(WORDFENCE_ACTIVATE_DELAY_MS);

      const chain = await wpRemoteGetChain(
        api,
        setupUser.username,
        setupUser.wpPath,
        setupUser.url
      );

      // Redirects are followed, so the homepage itself must answer 200: a 403 is
      // exactly the lock-out this test exists to catch.
      expectOneOf(chain.final.status, [200], describeChain(chain.hops));
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run(
        'deactivate Wordfence',
        () =>
          api.executeWpCliCommand(setupUser.username, [
            'plugin',
            'deactivate',
            WORDFENCE_SLUG,
            wpPath(setupUser),
          ]),
        {
          verify: async () =>
            (
              await api.executeWpCliCommand(setupUser.username, [
                'plugin',
                'is-active',
                WORDFENCE_SLUG,
                wpPath(setupUser),
              ])
            ).exit_code !== 0,
          describe: () => 'Wordfence is still active',
        }
      );
      await cleanup.finish();
    }
  });
});
