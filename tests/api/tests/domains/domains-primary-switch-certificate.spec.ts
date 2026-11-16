import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import { expectStatus } from '@/helpers/expect-status';
import { TEST_SSL_CERT, TEST_SSL_CERT_CN, TEST_SSL_KEY } from '@/test-data/static/test-ssl-cert';

/** A primary-domain switch keeps the certificate installed on the domain it moves away. */
test.describe('a primary-domain switch', () => {
  test('keeps the certificate installed on the old main domain', async ({
    api,
    authedRequest,
    domainFactory,
    userFactory,
  }) => {
    test.setTimeout(Timeouts.deploy);

    const user = await userFactory.createSimpleUser();
    const main = user.domain;
    const addon = await domainFactory.createAddonDomain(user.username, main);

    await test.step('install a custom certificate on the main domain', async () => {
      const installed = await authedRequest.put(
        `projects/${user.username}/domains/${main}/install-ssl-cert`,
        { data: { cert: TEST_SSL_CERT, key: TEST_SSL_KEY, ca: TEST_SSL_CERT } }
      );
      await expectStatus(installed, 200);

      const readBack = await api.getInstalledSslCertificate(user.username, main);
      expect(readBack.data.common_name, 'the certificate installed on the main domain').toBe(
        TEST_SSL_CERT_CN
      );
    });

    await test.step('switch: remove the addon, rename the main domain to it, add the old name again', async () => {
      await api.deleteDomain(user.username, addon);
      await api.updateUser(user.username, { domain: addon });
      await api.createDomain(user.username, { domain: main, type: 'addon' });

      const domains = (await api.listUserDomains(user.username)).data;
      expect(domains.find((entry) => entry.domain === addon)?.type, 'the new main domain').toBe(
        'main'
      );
      expect(domains.find((entry) => entry.domain === main)?.type, 'the old main domain').toBe(
        'addon'
      );
    });

    await test.step('the old main domain still carries its certificate', async () => {
      const kept = await api.getInstalledSslCertificate(user.username, main);
      expect(kept.data.certificate, 'the kept certificate material').toContain('BEGIN CERTIFICATE');
      expect(kept.data.common_name, 'the certificate is the one that was installed').toBe(
        TEST_SSL_CERT_CN
      );
    });
  });
});
