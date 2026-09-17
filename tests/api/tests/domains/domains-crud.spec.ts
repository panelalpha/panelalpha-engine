import { expect, test } from '@/fixtures/test-options';
import { wwwAliasWouldAnswer } from '@/config/site-domain';
import { expectOneOf } from '@/helpers/expect-one-of';

test.describe('user domains', () => {
  test('the main domain is listed', { tag: ['@smoke'] }, async ({ api, setupUser }) => {
    const { data } = await api.listUserDomains(setupUser.username);
    expect(data.length).toBeGreaterThanOrEqual(1);
  });

  test('the main domain has a www alias', async ({ domainAssertions, setupUser }) => {
    test.skip(
      !wwwAliasWouldAnswer(setupUser.domain),
      'www is not registered under panelalpha.online; the proxy serves only the exact label.'
    );
    await domainAssertions.verifyWwwAliasExists(setupUser.username, setupUser.domain);
  });

  test('an addon domain cannot shadow the main domain with a www prefix', async ({
    authedRequest,
    setupUser,
  }) => {
    const response = await authedRequest.post(`projects/${setupUser.username}/domains`, {
      data: { domain: `www.${setupUser.domain}`, type: 'addon' },
    });

    expect(response.status()).toBe(422);
    expect(((await response.json()) as { message?: string }).message).toBe(
      'Domain already exists.'
    );
  });

  test('an unknown domain returns 404', async ({ authedRequest, setupUser }) => {
    const response = await authedRequest.get(
      `projects/${setupUser.username}/domains/nonexistent-${Date.now()}.${setupUser.domain}`
    );
    expect(response.status()).toBe(404);
  });
});

/**
 * The addon domain lifecycle, in order.
 *
 * `serial` because each step works on the domain the previous one created — and
 * the last step deletes it, which is the point of the final assertion.
 */
test.describe('addon domain lifecycle', () => {
  test.describe.configure({ mode: 'serial' });

  let addonDomain: string;

  test('create an addon domain', async ({ domainFactory, domainAssertions, setupUser }) => {
    addonDomain = await domainFactory.createAddonDomain(setupUser.username, setupUser.domain);
    await domainAssertions.verifyDomainExists(setupUser.username, addonDomain);
  });

  test('read its details', async ({ api, setupUser }) => {
    expect((await api.getDomain(setupUser.username, addonDomain)).data).toBeTruthy();
  });

  test('update its configuration', async ({ api, setupUser }) => {
    await api.updateDomain(setupUser.username, addonDomain, {
      document_root: `/${addonDomain}/public_html`,
      redirect_enabled: false,
      force_https_redirect: false,
    });
  });

  test('turn on the HTTPS redirect', async ({ api, setupUser }) => {
    await api.updateDomain(setupUser.username, addonDomain, {
      document_root: `/${addonDomain}/public_html`,
      redirect_enabled: false,
      force_https_redirect: true,
    });

    const { details } = (await api.getDomain(setupUser.username, addonDomain)).data;
    expect((details as Record<string, unknown> | undefined)?.force_https_redirect).toBe(true);
  });

  test('delete it', async ({ domainFactory, domainAssertions, setupUser }) => {
    await domainFactory.deleteDomain(setupUser.username, addonDomain);
    await domainAssertions.verifyDomainNotExists(setupUser.username, addonDomain);
  });
});

test.describe('subdomain lifecycle', () => {
  test('a subdomain can be created and deleted', async ({
    api,
    authedRequest,
    domainAssertions,
    setupUser,
  }) => {
    const subdomain = `sub${Date.now()}.${setupUser.domain}`;

    const response = await authedRequest.post(`projects/${setupUser.username}/domains`, {
      data: { domain: subdomain, type: 'sub', parent_domain: setupUser.domain },
    });
    // A 422 is only acceptable for one reason: the account is at its subdomain
    // limit, which this test is not about. Any other refusal is the bug the
    // test exists to catch, so the body has to say so before it skips.
    expectOneOf(response.status(), [201, 422]);
    if (response.status() === 422) {
      const refusal = await response.text();
      expect(
        refusal.toLowerCase(),
        `subdomain creation was refused for something other than a limit: ${refusal}`
      ).toMatch(/limit|maximum|exceed|quota/);
      test.skip(true, 'The account is at its subdomain limit.');
    }

    const created =
      ((await response.json()) as { data?: { domain?: string } }).data?.domain ?? subdomain;

    await domainAssertions.verifyDomainExists(setupUser.username, created);

    await api.deleteDomain(setupUser.username, created);
    await domainAssertions.verifyDomainNotExists(setupUser.username, created);
  });
});
