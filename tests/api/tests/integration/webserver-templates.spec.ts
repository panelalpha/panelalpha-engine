import { expect, test } from '@/fixtures/test-options';
import { readTemplate, templateExists, templatesAvailable } from '@/helpers/webserver-templates';
import {
  getWebserverInfo,
  resolveAcmeChallengeVhostTemplates,
  resolveTemplates,
  resolveVirtualHostTemplates,
} from '@/helpers/webserver-helpers';
import { expectStatus } from '@/helpers/expect-status';

const ERROR_CODES = [403, 500, 502, 503] as const;
const ERROR_PAGES_ROOT = '/opt/panelalpha/shared-hosting/webserver-config/error-pages';

test.describe('shipped webserver templates', () => {
  test.beforeEach(() => {
    test.skip(!templatesAvailable(), 'The engine templates directory is not present.');
  });

  test('the real-IP configuration is included for the active webserver', async ({ api }) => {
    const { slug } = await getWebserverInfo(api);
    const templates = resolveTemplates(slug);
    test.skip(!templates, `No templates are mapped for the webserver "${slug}".`);

    for (const name of templates!) {
      test.skip(!templateExists(name), `Template ${name} is not shipped.`);

      const contents = readTemplate(name);
      expect(contents.length, `${name} is empty`).toBeGreaterThan(0);

      // LiteSpeed reads the client IP from its own listener configuration
      // instead of an included snippet.
      if (!slug.includes('litespeed')) {
        expect(contents, `${name} no longer includes the Cloudflare real-IP config`).toContain(
          'cloudflare-realip'
        );
      }
    }
  });

  /**
   * Let's Encrypt validates over plain HTTP, so the `:80` server block has to
   * answer the challenge path *before* it redirects everything to HTTPS. Order
   * matters here, which is why the positions are compared rather than just
   * checking the directives exist.
   */
  test('the ACME challenge exception precedes the HTTPS redirect', async ({ api }) => {
    const { slug } = await getWebserverInfo(api);
    const templates = resolveAcmeChallengeVhostTemplates(slug);
    test.skip(
      !templates,
      `The ACME vhost exception applies to nginx and nginx-proxy only; this engine runs ${slug}.`
    );

    for (const name of templates!) {
      test.skip(!templateExists(name), `Template ${name} is not shipped.`);

      // Everything before the SSL conditional is the plain-HTTP server block.
      const httpBlock = readTemplate(name).split(/@if\s*\(!empty\(\$ssl_enabled\)\)/)[0];

      const acmeAt = httpBlock.indexOf('location ^~ /.well-known/acme-challenge/');
      const redirectAt = httpBlock.indexOf('return 301 https://$host$request_uri;');
      const rootLocationAt = httpBlock.indexOf('location / {');

      expect(acmeAt, `${name} declares no ACME location in its :80 block`).toBeGreaterThanOrEqual(
        0
      );
      expect(rootLocationAt, `${name} puts location / before the ACME exception`).toBeGreaterThan(
        acmeAt
      );
      expect(redirectAt, `${name} redirects to HTTPS before the ACME exception`).toBeGreaterThan(
        acmeAt
      );

      const acmeBlock = httpBlock.slice(acmeAt, acmeAt + 400);
      expect(acmeBlock, `${name} ACME location has no alias to the challenges dir`).toContain(
        'alias {{ $http_acme_challenges_dir }}'
      );
      expect(acmeBlock, `${name} ACME location is not served as text/plain`).toContain(
        'default_type text/plain;'
      );
    }
  });

  test('custom error pages are wired up for every server error', async ({ api }) => {
    const { slug } = await getWebserverInfo(api);
    const templates = resolveVirtualHostTemplates(slug);
    test.skip(!templates, `No vhost templates are mapped for the webserver "${slug}".`);

    for (const name of templates!) {
      test.skip(!templateExists(name), `Template ${name} is not shipped.`);

      const contents = readTemplate(name);
      expect(contents, `${name} does not reference the error pages root`).toContain(
        ERROR_PAGES_ROOT
      );

      for (const code of ERROR_CODES) {
        expect(contents, `${name} maps no custom page for ${code}`).toContain(
          `{{ $user }}-error-pages/${code}.html`
        );
      }
    }
  });
});

test.describe('custom error pages over HTTP', () => {
  const EXPECTED_TEXT: Record<number, string> = {
    403: 'Forbidden',
    500: 'Internal Server Error',
    502: 'Bad Gateway',
    503: 'Service Unavailable',
  };

  for (const code of ERROR_CODES) {
    test(`the ${code} page is served`, async ({ api, anonymousRequest, setupUser }) => {
      const { slug } = await getWebserverInfo(api);

      // nginx marks the error-page directory `internal`, so it cannot be fetched
      // directly; the template assertions above cover those stacks instead.
      test.skip(
        slug.includes('nginx'),
        'nginx serves its error pages internally, so they cannot be fetched directly.'
      );

      const url = `https://${setupUser.domain}/${setupUser.username}-error-pages/${code}.html`;
      const isOls = slug.includes('openlitespeed');

      const response = await anonymousRequest.get(url, { ignoreHTTPSErrors: true });
      const body = await response.text();

      if (response.status() !== 200) {
        await test.info().attach(`error-page-${code}.html`, { body, contentType: 'text/html' });
      }

      await expectStatus(response, 200, `${url} answered ${response.status()}`);
      expect(body, `the ${code} page has its expected text`).toContain(EXPECTED_TEXT[code]);

      // Recorded for the report; OpenLiteSpeed needs longer to publish these.
      test.info().annotations.push({
        type: 'webserver',
        description: isOls ? `${slug} (slow to publish error pages)` : slug,
      });
    });
  }
});

test.describe('webserver health', () => {
  test('standalone nginx does not answer 502', async ({ api, anonymousRequest, setupUser }) => {
    const { slug } = await getWebserverInfo(api);
    const isStandaloneNginx =
      slug.includes('nginx') && !slug.includes('proxy') && !slug.includes('apache');
    test.skip(!isStandaloneNginx, `This test applies to standalone nginx; got ${slug}.`);

    const url = `https://${setupUser.domain}/`;
    const response = await anonymousRequest.get(url, { ignoreHTTPSErrors: true });

    if (response.status() === 502) {
      await test
        .info()
        .attach('nginx-502.html', { body: await response.text(), contentType: 'text/html' });
    }

    expect(response.status(), `${url} answered 502 — PHP-FPM is likely down`).not.toBe(502);
  });
});
