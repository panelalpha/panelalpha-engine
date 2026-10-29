import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { expectStatus } from '@/helpers/expect-status';
import { rand } from '@/helpers/random';
import { delay } from '@/helpers/retry';
import { TEST_SSL_CERT, TEST_SSL_KEY } from '@/test-data/static/test-ssl-cert';

const SSL_LIST_RETRY_DELAY_MS = 3_000;
const SSL_LIST_MAX_WAIT_MS = 90_000;

test.describe('SSL certificates', () => {
  test('installed certificates can be listed', async ({ api, setupUser }) => {
    const { data } = await api.listSslCertificates(setupUser.username);
    expect(Array.isArray(data)).toBe(true);
  });

  const invalidPayloads = [
    ['a malformed certificate', { cert: 'invalid-cert', key: TEST_SSL_KEY, ca: TEST_SSL_CERT }],
    ['a malformed key', { cert: TEST_SSL_CERT, key: 'invalid-key', ca: TEST_SSL_CERT }],
    ['a non-string CA', { cert: TEST_SSL_CERT, key: TEST_SSL_KEY, ca: 123 }],
  ] as const;

  for (const [label, payload] of invalidPayloads) {
    test(`refuses ${label}`, async ({ authedRequest, domainFactory, setupUser }) => {
      const domain = await domainFactory.createAddonDomain(setupUser.username, setupUser.domain);

      try {
        const response = await authedRequest.put(
          `projects/${setupUser.username}/domains/${domain}/install-ssl-cert`,
          { data: payload }
        );
        expectOneOf(response.status(), [400, 422]);
      } finally {
        await domainFactory.deleteDomain(setupUser.username, domain);
      }
    });
  }

  test('a certificate installed on an addon domain can be read back', async ({
    api,
    domainFactory,
    settings,
    setupUser,
  }) => {
    const domain = await domainFactory.createAddonDomain(setupUser.username, setupUser.domain);

    try {
      await api.installSslCertificate(
        setupUser.username,
        domain,
        TEST_SSL_CERT,
        TEST_SSL_KEY,
        TEST_SSL_CERT
      );

      const deadline =
        Date.now() + Math.max(settings.timing.propagationDelay * 6, SSL_LIST_MAX_WAIT_MS);
      const wanted = domain.toLowerCase();
      let visible = false;

      while (!visible && Date.now() < deadline) {
        try {
          visible = Boolean(
            (await api.getInstalledSslCertificate(setupUser.username, domain)).data
          );
        } catch {
          // Older engines only surface it in the list once the webserver reloads.
          const { data: certificates } = await api.listSslCertificates(setupUser.username);
          visible = certificates.some(
            (certificate) =>
              typeof certificate.common_name === 'string' &&
              [wanted, `www.${wanted}`].includes(certificate.common_name.toLowerCase())
          );
        }
        if (!visible) {
          await delay(SSL_LIST_RETRY_DELAY_MS);
        }
      }

      expect(visible, `the certificate for ${domain} never became visible`).toBe(true);

      const installed = await api.getInstalledSslCertificate(setupUser.username, domain);
      expect(installed.data?.certificate).toContain('BEGIN CERTIFICATE');
    } finally {
      await domainFactory.deleteDomain(setupUser.username, domain);
    }
  });
});

test.describe('installed-ssl-cert lookups', () => {
  test('the main domain answers deterministically', async ({ authedRequest, setupUser }) => {
    const response = await authedRequest.get(
      `projects/${setupUser.username}/domains/${setupUser.domain}/installed-ssl-cert`
    );
    // 404 and 422 both mean "no certificate here", depending on engine version.
    expectOneOf(response.status(), [200, 404, 422]);
  });

  test('an unknown domain is refused and says so', async ({ authedRequest, setupUser }) => {
    const response = await authedRequest.get(
      `projects/${setupUser.username}/domains/missing-${rand()}.${setupUser.domain}/installed-ssl-cert`
    );

    expectOneOf(response.status(), [404, 422]);
    expect((await response.text()).toLowerCase()).toMatch(/domain|not found|validation|missing/);
  });

  test('an unknown user is refused and says so', async ({ authedRequest, setupUser }) => {
    const response = await authedRequest.get(
      `projects/nouser_${rand()}/domains/${setupUser.domain}/installed-ssl-cert`
    );

    expectOneOf(response.status(), [404, 422]);
    expect((await response.text()).toLowerCase()).toMatch(/user|not found|validation|missing/);
  });
});

test.describe('ssl-check reads what the domain really serves', () => {
  const statuses = [
    'trusted',
    'self_signed',
    'untrusted_issuer',
    'expired',
    'not_yet_valid',
    'domain_mismatch',
    'unreadable',
    'not_resolving',
    'refused_address',
    'unreachable',
  ];

  test('the main domain answers a handshake on this host', async ({ api, setupUser }) => {
    const { data } = await api.checkSsl(setupUser.username, setupUser.domain);

    expect(data.domain).toBe(setupUser.domain);
    expect(statuses).toContain(data.status);
    expect(Array.isArray(data.dns.addresses)).toBe(true);
    expect(data.engine?.answered, data.engine?.error ?? '').toBe(true);
    expect(statuses).toContain(data.engine?.certificate?.status);
    expect(data.engine?.certificate?.fingerprint_sha256).toMatch(/^[0-9a-f]{64}$/);
    expect(data.engine?.certificate?.expires_at).toMatch(/^\d{4}-\d{2}-\d{2}T/);
    expect(data.engine?.certificate?.signer).toBeTruthy();
    expect(data.engine?.certificate?.key?.type).toBeTruthy();
    if (data.public?.certificate) {
      expect(data.expires_at).toBe(data.public.certificate.expires_at);
      expect(data.signer).toEqual(data.public.certificate.signer);
    }
  });

  test('an installed certificate is the one the webserver presents', async ({
    api,
    domainFactory,
    settings,
    setupUser,
  }) => {
    const domain = await domainFactory.createAddonDomain(setupUser.username, setupUser.domain);

    try {
      await api.installSslCertificate(
        setupUser.username,
        domain,
        TEST_SSL_CERT,
        TEST_SSL_KEY,
        TEST_SSL_CERT
      );

      const deadline =
        Date.now() + Math.max(settings.timing.propagationDelay * 6, SSL_LIST_MAX_WAIT_MS);
      let served: boolean | null | undefined = null;

      while (served !== true && Date.now() < deadline) {
        served = (await api.checkSsl(setupUser.username, domain)).data.engine?.serves_installed;
        if (served !== true) {
          await delay(SSL_LIST_RETRY_DELAY_MS);
        }
      }

      expect(served, `${domain} never served the certificate installed on it`).toBe(true);
    } finally {
      await domainFactory.deleteDomain(setupUser.username, domain);
    }
  });

  test('an unknown domain is refused', async ({ authedRequest, setupUser }) => {
    const response = await authedRequest.get(
      `projects/${setupUser.username}/domains/missing-${rand()}.${setupUser.domain}/ssl-check`
    );

    await expectStatus(response, 404);
  });
});
