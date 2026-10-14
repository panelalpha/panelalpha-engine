import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { uniqueId } from '@/helpers/random';
import { waitForCondition } from '@/helpers/retry';
import { getPeerCertificate, installedTestSslCertificatePresented } from '@/helpers/tls-helpers';
import { getWebserverInfo, supportsForceHttpsRedirect } from '@/helpers/webserver-helpers';
import { expectStatus } from '@/helpers/expect-status';
import { TEST_SSL_CERT, TEST_SSL_KEY } from '@/test-data/static/test-ssl-cert';

const CERT_PRESENTED_TIMEOUT_MS = 30_000;

/**
 * Installs the fixture certificate and waits for the webserver to present it.
 * Callers must use `tunnel: none` so :443 is this engine, not the
 * panelalpha.online proxy.
 */
async function installCertificateAndWait(
  api: Parameters<typeof getWebserverInfo>[0],
  user: { username: string; domain: string }
): Promise<void> {
  await api.installSslCertificate(
    user.username,
    user.domain,
    TEST_SSL_CERT,
    TEST_SSL_KEY,
    TEST_SSL_CERT
  );

  let lastCert = 'no certificate read yet';
  await waitForCondition(
    async () => {
      const cert = await getPeerCertificate(user.domain, user.domain);
      const subject = String(cert.subject?.CN ?? '(none)');
      const issuer = String(cert.issuer?.CN ?? '(none)');
      lastCert = `subject CN ${subject}, issuer CN ${issuer}`;
      return installedTestSslCertificatePresented(cert);
    },
    {
      timeout: CERT_PRESENTED_TIMEOUT_MS,
      interval: 2_000,
      message: `${user.domain} never presented the installed certificate over HTTPS`,
      describeLast: () => `presented ${lastCert}`,
    }
  );
}

test.describe('SSL end to end', () => {
  test('an installed certificate is served, and installing it again is harmless', async ({
    api,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser({ tunnel: 'none' });

    await test.step('install the certificate', async () => {
      await installCertificateAndWait(api, user);
      expect(
        (await api.getInstalledSslCertificate(user.username, user.domain)).data?.certificate,
        'the installed certificate is a PEM certificate'
      ).toContain('BEGIN CERTIFICATE');
    });

    await test.step('install it again', async () => {
      await installCertificateAndWait(api, user);
      expect(
        (await api.getInstalledSslCertificate(user.username, user.domain)).data?.certificate,
        'the certificate is still a PEM certificate after the second install'
      ).toContain('BEGIN CERTIFICATE');
    });
  });
});

/**
 * `force_https_redirect` is only implemented for the nginx-proxy stack, so these
 * skip everywhere else rather than asserting behaviour that was never built.
 *
 * Redirect on/off and the ACME exemption share one account and one certificate
 * install — each used to pay that cost separately (~4 minutes for two tests).
 */
test.describe('force HTTPS redirect', () => {
  test.beforeEach(async ({ api }) => {
    const { slug } = await getWebserverInfo(api);
    test.skip(
      !supportsForceHttpsRedirect(slug),
      `force_https_redirect is implemented for nginx-proxy only; this engine runs ${slug}.`
    );
  });

  const setRedirect = async (
    api: Parameters<typeof getWebserverInfo>[0],
    user: { username: string; domain: string },
    enabled: boolean
  ) =>
    api.updateDomain(user.username, user.domain, {
      document_root: `/${user.domain}/public_html`,
      redirect_enabled: false,
      force_https_redirect: enabled,
    });

  test('HTTP is redirected only while the flag is on, and ACME stays on HTTP', async ({
    api,
    anonymousRequest,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser({ tunnel: 'none' });
    await test.step('install a certificate', async () => {
      await installCertificateAndWait(api, user);
    });

    const fetchOverHttp = () =>
      anonymousRequest.get(`http://${user.domain}/`, {
        ignoreHTTPSErrors: true,
        maxRedirects: 0,
      });

    await test.step('turn the redirect on and wait for it', async () => {
      await setRedirect(api, user, true);
      let lastRedirect = 'no response yet';
      await waitForCondition(
        async () => {
          const response = await fetchOverHttp();
          lastRedirect = `HTTP ${response.status()}, location ${response.headers().location ?? '(none)'}`;
          return [301, 302, 307, 308].includes(response.status());
        },
        {
          timeout: 30_000,
          interval: 2_000,
          message: 'HTTP was not redirected after force_https_redirect was enabled',
          describeLast: () => lastRedirect,
        }
      );
      const redirected = await fetchOverHttp();
      expectOneOf(redirected.status(), [301, 302, 307, 308], 'HTTP was not redirected');
      expect(redirected.headers().location, 'the redirect points at https').toMatch(/https:\/\//i);
    });

    const token = uniqueId('acme');
    const contents = `${token}.validation-payload`;
    const challengeUrl = `http://${user.domain}/.well-known/acme-challenge/${token}`;

    await test.step('check the ACME challenge stays on HTTP', async () => {
      try {
        await api.createHttpAcmeChallenge(user.domain, { token, content: contents });
        let lastChallenge = 'no response yet';
        await waitForCondition(
          async () => {
            const response = await anonymousRequest.get(challengeUrl, {
              ignoreHTTPSErrors: true,
              maxRedirects: 0,
            });
            lastChallenge = `HTTP ${response.status()}, location ${response.headers().location ?? '(none)'}`;
            return response.status() === 200;
          },
          {
            timeout: 30_000,
            interval: 2_000,
            message: `${challengeUrl} never answered 200 after the HTTP-01 challenge was created`,
            describeLast: () => lastChallenge,
          }
        );

        const challenge = await anonymousRequest.get(challengeUrl, {
          ignoreHTTPSErrors: true,
          maxRedirects: 0,
        });

        await expectStatus(
          challenge,
          200,
          `the ACME challenge was redirected to ${challenge.headers().location ?? '(nowhere)'}`
        );
        expect(challenge.headers().location, 'the challenge has no Location header').toBeFalsy();
        expect((await challenge.text()).trim(), 'the challenge body is the stored content').toBe(
          contents
        );

        const homepage = await anonymousRequest.get(`http://${user.domain}/`, {
          ignoreHTTPSErrors: true,
          maxRedirects: 0,
        });
        expectOneOf(homepage.status(), [301, 302, 307, 308]);
      } finally {
        await api.deleteAllHttpAcmeChallengesSafe(user.domain);
      }
    });

    await test.step('turn the redirect off and wait for plain HTTP', async () => {
      await setRedirect(api, user, false);
      let lastDirect = 'no response yet';
      await waitForCondition(
        async () => {
          const response = await fetchOverHttp();
          lastDirect = `HTTP ${response.status()}, location ${response.headers().location ?? '(none)'}`;
          return response.status() === 200;
        },
        {
          timeout: 30_000,
          interval: 2_000,
          message: 'HTTP was still redirected after the flag was turned off',
          describeLast: () => lastDirect,
        }
      );
      const direct = await fetchOverHttp();
      await expectStatus(direct, 200, 'HTTP was still redirected after the flag was turned off');
      expect(direct.headers().location, 'no Location header once the flag is off').toBeFalsy();
    });
  });
});
