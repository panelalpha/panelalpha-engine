import { expect, test } from '@/fixtures/test-options';
import { systemInfoResponseSchema } from '@/schemas';

test.describe('system info', () => {
  test('reports a webserver and an engine version', { tag: ['@smoke'] }, async ({ api }) => {
    const { data } = await api.getSystemInfo();

    expect(data.webserver).toBeDefined();
    expect(data.version).toBeDefined();
  });

  test('matches its schema', async ({ api }) => {
    const parsed = systemInfoResponseSchema.safeParse(await api.getSystemInfo());
    expect(parsed.success, parsed.error?.message).toBe(true);
  });

  test('exposes the IPv4 NAT fields', async ({ api }) => {
    const { data } = await api.getSystemInfo();

    expect(typeof data.ipv4_nat_mode).toBe('boolean');
    expect(Array.isArray(data.ipv4_nat_maps)).toBe(true);
  });

  test('reports the Engine default_tunnel setting', async ({ api }) => {
    const { data } = await api.getSystemInfo();

    expect(data).toHaveProperty('default_tunnel');
    expect([null, 'panelalpha', 'none']).toContain(data.default_tunnel ?? null);
  });

  test('SSL certificates are listed per user', async ({ api, setupUser }) => {
    expect(Array.isArray((await api.listSslCertificates(setupUser.username)).data)).toBe(true);
  });
});
