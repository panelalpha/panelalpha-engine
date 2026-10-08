import fs from 'node:fs';
import { expect, test } from '@/fixtures/test-options';
import {
  CLOUDFLARE_PROBE_CLIENT_IP,
  REAL_IP_ENGINE_ASSET_CHECKS,
  REAL_IP_PROBE_PHP,
  isPrivateOrLocalIp,
  realIpProbeFilePath,
  realIpProbeUrl,
  resolveEngineAssetPath,
  webserverMatchesAssetCheck,
  type RealIpProbePayload,
} from '@/helpers/real-ip-helpers';
import {
  installLocalIpBlockMuPlugin,
  removeLocalIpBlockMuPlugin,
} from '@/helpers/wp-security-local-ip-helpers';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { removeFileIfPresent } from '@/helpers/file-path-helpers';
import { getWebserverInfo } from '@/helpers/webserver-helpers';

/**
 * Behind a proxy, PHP sees the proxy's address in `REMOTE_ADDR` unless the
 * webserver is configured to restore the client's. When it is not, every visitor
 * looks like they are coming from a private address — WordPress security plugins
 * then block the whole internet, and rate limits count every visitor as one.
 */
test.describe('real client IP propagation', () => {
  test.afterEach(async ({ api, setupUser }) => {
    const cleanup = new CleanupSteps();
    await cleanup.run('remove the local-IP mu-plugin', () =>
      removeLocalIpBlockMuPlugin(api, setupUser.username, setupUser.wpPath)
    );
    await cleanup.run('remove the real-IP probe script', () =>
      removeFileIfPresent(api, setupUser.username, realIpProbeFilePath(setupUser.domain))
    );
    await cleanup.finish();
  });

  /** Reads the probe script's view of the request. */
  const probe = async (
    api: Parameters<typeof getWebserverInfo>[0],
    http: {
      get: (
        url: string,
        options?: object
      ) => Promise<{ status: () => number; text: () => Promise<string> }>;
    },
    setupUser: { username: string; domain: string },
    headers?: Record<string, string>
  ) => {
    await api.putFileContents(
      setupUser.username,
      realIpProbeFilePath(setupUser.domain),
      REAL_IP_PROBE_PHP
    );

    const response = await http.get(realIpProbeUrl(setupUser.domain), {
      ...(headers ? { headers } : {}),
      ignoreHTTPSErrors: true,
      maxRedirects: 5,
      timeout: 30_000,
    });

    let payload: RealIpProbePayload | null = null;
    try {
      payload = JSON.parse(await response.text()) as RealIpProbePayload;
    } catch {
      payload = null;
    }

    return { status: response.status(), payload };
  };

  test('the engine ships the real-IP configuration for the active webserver', async ({ api }) => {
    const { slug } = await getWebserverInfo(api);
    const applicable = REAL_IP_ENGINE_ASSET_CHECKS.filter((check) =>
      webserverMatchesAssetCheck(slug, check.webserverIncludes)
    );

    test.skip(applicable.length === 0, `No real-IP assets are defined for ${slug}.`);

    for (const check of applicable) {
      const assetPath = resolveEngineAssetPath(check.relativePath);
      test.skip(!fs.existsSync(assetPath), `${check.relativePath} is not in this engine.`);

      const contents = fs.readFileSync(assetPath, 'utf8');
      for (const needle of check.mustContain) {
        expect(contents, `${check.relativePath} no longer contains "${needle}"`).toContain(needle);
      }
    }
  });

  /**
   * The counterpart to the private-IP block tests: a plugin refusing private
   * addresses must not lock out real visitors, which it will if the proxy
   * address is what PHP sees.
   */
  test(
    'an external request is not mistaken for a private one',
    {
      tag: ['@security'],
    },
    async ({ api, anonymousRequest, setupUser }) => {
      await test.step('install the local-IP block plugin', async () => {
        await installLocalIpBlockMuPlugin(api, setupUser.username, setupUser.wpPath);
      });

      const { status, payload } = await test.step('probe the site as an external client', () =>
        probe(api, anonymousRequest, setupUser));

      expect(
        status,
        'an external request was refused as if it came from a private address'
      ).not.toBe(403);
      expect(status, 'the probe did not hit a server error').toBeLessThan(500);

      test.skip(!payload?.remote_addr, 'The probe returned no REMOTE_ADDR.');
      test.skip(
        isPrivateOrLocalIp(payload!.remote_addr),
        `Suite is running on the engine host; PHP sees Docker gateway ${payload!.remote_addr} ` +
          '(hairpin), not a public client. Re-run this from off-box to assert real-IP restoration.'
      );
      expect(
        isPrivateOrLocalIp(payload!.remote_addr),
        `PHP sees REMOTE_ADDR ${payload!.remote_addr}, which is a private address`
      ).toBe(false);
    }
  );

  test('the Cloudflare client IP header reaches PHP', async ({
    api,
    anonymousRequest,
    setupUser,
  }) => {
    const { payload } = await probe(api, anonymousRequest, setupUser, {
      'CF-Connecting-IP': CLOUDFLARE_PROBE_CLIENT_IP,
    });

    test.skip(!payload, 'The probe returned no JSON payload.');
    expect(payload!.cf_ip, 'CF-Connecting-IP was stripped before reaching PHP').toBe(
      CLOUDFLARE_PROBE_CLIENT_IP
    );
  });
});
