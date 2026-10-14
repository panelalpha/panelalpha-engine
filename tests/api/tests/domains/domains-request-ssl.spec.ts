import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { expectStatus } from '@/helpers/expect-status';

test.describe('request SSL certificate', () => {
  test('dry_run returns a plan without changing the installed cert', async ({ api, setupUser }) => {
    const before = await api.getInstalledSslCertificate(setupUser.username, setupUser.domain);
    const planned = await api.requestSslCertificateRaw(setupUser.username, setupUser.domain, {
      dry_run: true,
    });
    await expectStatus(planned, 200);
    const after = await api.getInstalledSslCertificate(setupUser.username, setupUser.domain);
    expect(after.data).toEqual(before.data);
  });

  test('an unknown domain is a 404', async ({ api, setupUser }) => {
    const response = await api.requestSslCertificateRaw(
      setupUser.username,
      `missing.${setupUser.domain}`,
      { dry_run: true }
    );
    expectOneOf(response.status, [404, 422]);
  });
});
