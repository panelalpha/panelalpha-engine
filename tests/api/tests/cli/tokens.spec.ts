import { expect, test } from '@/fixtures/test-options';
import { uniqueId } from '@/helpers/random';
import { insecureFetch } from '@/helpers/insecure-fetch';

test.describe('pae-artisan token commands', () => {
  test.beforeEach(({ hostExec }) => {
    test.skip(!hostExec, 'pae-artisan is not reachable from this runner.');
  });

  test('api token create, list and delete', async ({ hostExec, settings }) => {
    const name = uniqueId('cli-api-');
    const created = await hostExec!.pae(['api:token:create', name, '--short']);
    expect(created.exitCode).toBe(0);
    const token = created.stdout
      .split('\n')
      .map((line) => line.trim())
      .find((line) => /^\d+\|\S+$/.test(line));
    expect(token).toBeTruthy();

    const listed = await hostExec!.pae(['api:token:list']);
    expect(listed.exitCode).toBe(0);
    expect(listed.stdout).toContain(name);

    const id = token!.split('|')[0];
    const probe = await insecureFetch(`${settings.apiBaseUrl}test-connection`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    expect(probe.ok).toBe(true);

    const deleted = await hostExec!.pae(['api:token:delete', id]);
    expect(deleted.exitCode).toBe(0);

    const after = await insecureFetch(`${settings.apiBaseUrl}test-connection`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    expect([401, 403]).toContain(after.status);
  });

  test('mcp token create, list, revoke and delete agree with REST', async ({ hostExec, api }) => {
    const name = uniqueId('cli-mcp-');
    const created = await hostExec!.pae([
      'mcp:token:create',
      name,
      '--short',
      '--no-register',
      '--expires=1h',
    ]);
    expect(created.exitCode).toBe(0);
    const token = created.stdout
      .split('\n')
      .map((line) => line.trim())
      .find((line) => /^\d+\|\S+$/.test(line));
    expect(token).toBeTruthy();
    const id = Number(token!.split('|')[0]);

    try {
      const listed = await hostExec!.pae(['mcp:token:list']);
      expect(listed.exitCode).toBe(0);
      expect(listed.stdout).toContain(name);

      const rest = await api.listMcpTokens();
      expect(rest.data.some((row) => row.id === id)).toBe(true);

      const revoked = await hostExec!.pae(['mcp:token:revoke', String(id)]);
      expect(revoked.exitCode).toBe(0);
    } finally {
      await hostExec!.pae(['mcp:token:delete', String(id)]);
      await api.deleteMcpTokenSafe(id);
    }
  });
});
