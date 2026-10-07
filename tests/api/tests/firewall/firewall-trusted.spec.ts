import { expect, test } from '@/fixtures/test-options';
import { randomTestNetAddress, requireFirewall } from '@/helpers/firewall-helpers';
import { trustedAddressResponseSchema, trustedAddressesResponseSchema } from '@/schemas';

/**
 * Addresses the login protection never bans. Only documentation ranges
 * (RFC 5737) are trusted here, and each one is removed again.
 */
test.describe('Firewall trusted addresses', () => {
  const trusted = new Set<string>();

  test.beforeEach(async ({ api }) => {
    await requireFirewall(api);
  });

  test.afterEach(async ({ api }) => {
    for (const id of trusted) {
      await api.untrustAddressRaw(id);
    }
    trusted.clear();
  });

  test('an address is trusted, re-commented and removed', async ({ api }) => {
    const address = randomTestNetAddress();

    const { data: added } = trustedAddressResponseSchema.parse(
      await api.trustAddress(`${address}/32`, 'api-test office')
    );
    trusted.add(added.id);
    expect(added).toMatchObject({ address, comment: 'api-test office' });

    const listed = trustedAddressesResponseSchema.parse(await api.listTrustedAddresses()).data;
    expect(listed.filter((a) => a.address === address)).toEqual([added]);

    // Trusting it again keeps one entry, with the new comment.
    const { data: again } = trustedAddressResponseSchema.parse(
      await api.trustAddress(address, 'api-test office, second floor')
    );
    expect(again.id).toBe(added.id);
    const relisted = trustedAddressesResponseSchema.parse(await api.listTrustedAddresses()).data;
    expect(relisted.filter((a) => a.address === address).map((a) => a.comment)).toEqual([
      'api-test office, second floor',
    ]);

    expect((await api.untrustAddressRaw(added.id)).status).toBe(200);
    trusted.delete(added.id);
    expect((await api.untrustAddressRaw(added.id)).status).toBe(404);
    const after = trustedAddressesResponseSchema.parse(await api.listTrustedAddresses()).data;
    expect(after.map((a) => a.address)).not.toContain(address);
  });

  test('only an address can be trusted', async ({ api }) => {
    const invalid: Record<string, unknown>[] = [
      {},
      { address: 'example.com' },
      { address: '192.0.2.300' },
      { address: randomTestNetAddress(), comment: 'two\nlines' },
    ];
    for (const body of invalid) {
      const response = await api.trustAddressRaw(body);
      const id = (response.body as { data?: { id?: unknown } }).data?.id;
      if (typeof id === 'string') {
        trusted.add(id);
      }
      expect(response.status, JSON.stringify(body)).toBe(422);
    }
  });
});
