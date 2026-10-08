import { expect, test } from '@/fixtures/test-options';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { wwwAliasWouldAnswer } from '@/config/site-domain';
import { expectOneOf } from '@/helpers/expect-one-of';
import { delay, waitForCondition } from '@/helpers/retry';
import {
  getWebserverInfo,
  getWebserverPropagationDelay,
  httpScheme,
} from '@/helpers/webserver-helpers';
import { ensureWwwConfigInWpConfig, registerWpConfigSecrets } from '@/helpers/wp-config-helpers';
import { expectStatus } from '@/helpers/expect-status';

const WP_CONFIG_PROPAGATION_MS = 3_000;

/**
 * Serving a site at `www.` and redirecting the apex to it.
 *
 * Only the nginx-proxy stack wires the apex-to-www redirect in its vhost, so the
 * redirect tests skip elsewhere. The engine adds a www alias only when the
 * name's zone would actually answer for it — not under panelalpha.online.
 */
test.describe('www hostname', () => {
  test.beforeEach(({ setupUser }) => {
    test.skip(
      !wwwAliasWouldAnswer(setupUser.domain),
      'www is not registered under panelalpha.online; the proxy serves only the exact label.'
    );
  });

  test('the www alias exists for the main domain', async ({ api, setupUser }) => {
    const wwwDomain = `www.${setupUser.domain}`;

    let lastAliases = 'domain not listed yet';
    await waitForCondition(
      async () => {
        const domain = (await api.listUserDomains(setupUser.username)).data.find(
          (entry) => entry.domain === setupUser.domain
        );
        const aliases = domain?.details?.aliases ?? [];
        lastAliases = domain
          ? `aliases ${aliases.length > 0 ? aliases.join(', ') : '(none)'}`
          : `${setupUser.domain} not in the domain list`;
        // Engines that do not expose an aliases field cannot be checked here;
        // the domains suite covers the alias contract itself.
        return !domain?.details?.aliases || domain.details.aliases.includes(wwwDomain);
      },
      {
        timeout: 30_000,
        interval: 2_000,
        message: `${wwwDomain} never appeared as an alias of ${setupUser.domain}`,
        describeLast: () => lastAliases,
      }
    );
  });

  test('the www hostname serves the site', async ({ anonymousRequest, settings, setupUser }) => {
    const url = `${httpScheme(settings.apiBaseUrl)}://www.${setupUser.domain}/`;
    const response = await anonymousRequest.get(url, {
      ignoreHTTPSErrors: true,
      maxRedirects: 5,
    });
    const body = await response.text();

    if (response.status() !== 200) {
      await test.info().attach('www-response.html', { body, contentType: 'text/html' });
    }

    await expectStatus(response, 200, `${url} answered ${response.status()}`);
    expect(body.length, `${url} returned an empty body`).toBeGreaterThan(0);
  });
});

test.describe('apex to www redirect', () => {
  test('every apex path redirects to www', async ({
    api,
    anonymousRequest,
    settings,
    setupUser,
  }) => {
    test.skip(
      !wwwAliasWouldAnswer(setupUser.domain),
      'www is not registered under panelalpha.online; the proxy serves only the exact label.'
    );
    const { slug } = await getWebserverInfo(api);
    test.skip(
      slug !== 'nginx-proxy',
      `The apex-to-www vhost redirect is wired for nginx-proxy only; this engine runs ${slug}.`
    );

    const scheme = httpScheme(settings.apiBaseUrl);
    const wwwDomain = `www.${setupUser.domain}`;

    await test.step('point wp-config at the www host', async () => {
      const changed = await ensureWwwConfigInWpConfig(
        api,
        setupUser.username,
        setupUser.wpPath,
        wwwDomain
      );
      if (changed) {
        await delay(WP_CONFIG_PROPAGATION_MS);
      }

      const wpConfig = registerWpConfigSecrets(
        await api.getFileContent(setupUser.username, `${setupUser.wpPath}/wp-config.php`)
      );
      expect(wpConfig, 'WP_HOME does not point at the www host').toMatch(
        /define\s*\(\s*['"]WP_HOME['"]\s*,\s*['"][^'"]*www\.[^'"]*['"]/
      );
      expect(wpConfig, 'WP_SITEURL does not point at the www host').toMatch(
        /define\s*\(\s*['"]WP_SITEURL['"]\s*,\s*['"][^'"]*www\.[^'"]*['"]/
      );
    });

    const originalDocumentRoot = `/${setupUser.domain}/public_html`;

    await test.step('turn the apex redirect on', async () => {
      await api.updateDomain(setupUser.username, setupUser.domain, {
        document_root: originalDocumentRoot,
        redirect_enabled: true,
        redirect_url: `${scheme}://${wwwDomain}/`,
        force_https_redirect: false,
      });
      await delay(getWebserverPropagationDelay(slug));
    });

    const cleanup = new CleanupSteps();
    try {
      const paths = [
        ['the home page', '/'],
        ['wp-admin', '/wp-admin/'],
        ['a URL with a query string', '/?test=query'],
      ] as const;

      for (const [label, path] of paths) {
        await test.step(`${label} redirects to www`, async () => {
          const url = `${scheme}://${setupUser.domain}${path}`;
          const response = await anonymousRequest.get(url, {
            ignoreHTTPSErrors: true,
            maxRedirects: 0,
          });
          const location = response.headers().location;

          expectOneOf(response.status(), [301, 302, 307, 308], `${url} did not redirect`);
          expect(location, `${url} redirected without a Location header`).toBeTruthy();
          expect(location, `${url} redirected to ${location}`).toContain('www.');
          expect(location, `${url} redirected away from ${setupUser.domain}`).toContain(
            setupUser.domain
          );
        });
      }
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run('turn the apex redirect off', () =>
        api.updateDomain(setupUser.username, setupUser.domain, {
          document_root: originalDocumentRoot,
          redirect_enabled: false,
          force_https_redirect: false,
        })
      );
      await cleanup.finish();
    }
  });
});
