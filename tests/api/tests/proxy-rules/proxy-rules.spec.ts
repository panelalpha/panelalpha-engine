import { expect, test } from '@/fixtures/test-options';
import { isProxyRulesAvailable } from '@/helpers/sss-feature-helpers';
import { rand } from '@/helpers/random';
import { expectStatus } from '@/helpers/expect-status';

test.describe('Proxy rules', () => {
  test.beforeEach(async ({ api }) => {
    test.skip(!(await isProxyRulesAvailable(api)), 'Proxy rules are not available on this engine.');
  });

  test('the rule list is an array', async ({ api }) => {
    expect(Array.isArray((await api.listProxyRules()).data)).toBe(true);
  });

  test('an HTTP rule is created, read, updated and deleted', async ({ api }) => {
    // High ephemeral port so we do not collide with the engine's own listeners.
    const listenPort = 41000 + (Date.now() % 1000);
    const serverName = `${rand('pr')}.example.test`;

    const created = await api.createProxyRule({
      owner_scope: 'system',
      transport: 'http',
      listen_port: listenPort,
      server_name: serverName,
      upstream_host: '127.0.0.1',
      upstream_port: 8080,
      enabled: true,
    });

    try {
      expect(created.data.transport).toBe('http');
      expect(created.data.listen_port).toBe(listenPort);
      expect(created.data.server_name).toBe(serverName);
      expect(created.data.upstream_host).toBe('127.0.0.1');

      const fetched = await api.getProxyRule(created.data.id);
      expect(fetched.data.id).toBe(created.data.id);

      const updated = await api.updateProxyRule(created.data.id, {
        upstream_port: 8081,
        enabled: false,
      });
      expect(updated.data.upstream_port).toBe(8081);
      expect(updated.data.enabled).toBe(false);

      await api.deleteProxyRule(created.data.id);
      await expectStatus(await api.getProxyRuleRaw(created.data.id), 404);
    } finally {
      await api.deleteProxyRuleSafe(created.data.id);
    }
  });

  test('a wildcard rule on 443 says it is served with the default certificate', async ({ api }) => {
    const created = await api.createProxyRule({
      owner_scope: 'system',
      transport: 'http',
      listen_port: 443,
      server_name: `*.${rand('pr')}.example.test`,
      upstream_host: '127.0.0.1',
      upstream_port: 8080,
      enabled: false,
    });

    try {
      expect(
        (created as { warnings?: string[] }).warnings,
        'a disabled rule is not served'
      ).toBeUndefined();

      const enabled = await api.updateProxyRule(created.data.id, { enabled: true });
      expect((enabled as { warnings?: string[] }).warnings).toEqual([
        expect.stringContaining('default self-signed certificate'),
      ]);
    } finally {
      await api.deleteProxyRuleSafe(created.data.id);
    }
  });

  test('a missing rule returns 404', async ({ api }) => {
    await expectStatus(await api.getProxyRuleRaw(999_999_999), 404);
  });

  test('an invalid listen port is rejected', async ({ api }) => {
    const response = await api.createProxyRuleRaw({
      owner_scope: 'system',
      transport: 'http',
      listen_port: 0,
      server_name: 'bad.example.test',
      upstream_host: '127.0.0.1',
      upstream_port: 8080,
    });
    expect([400, 422]).toContain(response.status);
  });
});
