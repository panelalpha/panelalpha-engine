import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';

interface MailDnsRecord {
  type: 'spf' | 'dkim' | 'dmarc' | 'mx';
  host: string | null;
  record_type: string;
  expected: string | null;
  note: string;
  status?: string;
}

interface MailDns {
  domain: string;
  sending_domain: string;
  relay: string;
  checked: boolean;
  records: MailDnsRecord[];
}

/**
 * The DNS records a project's domain needs for the engine's mail to be accepted, and with
 * `check=1` what DNS holds against each of them.
 */
test.describe('mail DNS records', () => {
  test('the expected records name the domain and the way mail leaves', async ({
    authedRequest,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();
    const base = `projects/${user.username}/domains/${user.domain}/mail-dns`;

    const expected = await test.step('the expected records', async () => {
      const response = await authedRequest.get(base);
      await expectStatus(response, 200, 'GET mail-dns');
      const { data } = (await response.json()) as { data: MailDns };
      expect(data.domain).toBe(user.domain);
      expect(data.checked).toBe(false);
      expect(data.records.map((record) => record.type)).toEqual(['spf', 'dkim', 'dmarc', 'mx']);
      const spf = data.records.find((record) => record.type === 'spf')!;
      expect(spf.host).toBe(data.sending_domain);
      if (spf.expected === null) {
        expect(spf.note, 'an SPF record without a value says why').toMatch(
          /no public address|include/
        );
      } else {
        expect(spf.expected).toMatch(/^v=spf1\b/);
      }
      expect(data.records.find((record) => record.type === 'dmarc')!.host).toBe(
        `_dmarc.${data.sending_domain}`
      );
      return data;
    });

    await test.step('a DKIM selector names the DKIM record', async () => {
      const response = await authedRequest.get(`${base}?dkim_selector=S1`);
      await expectStatus(response, 200, 'GET mail-dns with a selector');
      const { data } = (await response.json()) as { data: MailDns };
      expect(data.records.find((record) => record.type === 'dkim')!.host).toBe(
        `s1._domainkey.${expected.sending_domain}`
      );
    });

    await test.step('check looks every record up', async () => {
      const response = await authedRequest.get(`${base}?check=1`);
      await expectStatus(response, 200, 'GET mail-dns?check=1');
      const { data } = (await response.json()) as { data: MailDns };
      expect(data.checked).toBe(true);
      for (const record of data.records) {
        expect(['ok', 'missing', 'wrong', 'unknown'], record.type).toContain(record.status);
      }
    });

    await test.step('a selector that is not a DNS label is refused', async () => {
      await expectStatus(await authedRequest.get(`${base}?dkim_selector=bad%20selector`), 422);
    });
  });

  test('a domain the project does not have is 404', async ({ authedRequest, userFactory }) => {
    const user = await userFactory.createSimpleUser();
    await expectStatus(
      await authedRequest.get(`projects/${user.username}/domains/not-${user.domain}/mail-dns`),
      404
    );
  });
});
