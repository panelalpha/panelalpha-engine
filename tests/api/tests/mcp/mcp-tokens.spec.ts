import { expect, test } from '@/fixtures/test-options';
import { rand } from '@/helpers/random';
import { expectStatus } from '@/helpers/expect-status';

test.describe('MCP tokens', () => {
  test('the token list is an array', async ({ api }) => {
    expect(Array.isArray((await api.listMcpTokens()).data)).toBe(true);
  });

  test('a token is created, listed, revoked and deleted', async ({ api }) => {
    const name = rand('mcp');
    const created = await api.createMcpToken(name);

    try {
      expect(created.data.name).toBe(name);
      expect(created.data.plain_text_token).toMatch(/^\d+\|/);
      expect(created.data.revoked_at).toBeNull();

      const listed = (await api.listMcpTokens()).data.find((token) => token.id === created.data.id);
      expect(listed, `token ${created.data.id} missing from the listing`).toBeDefined();
      expect(listed?.name).toBe(name);

      const revoked = await api.revokeMcpToken(created.data.id);
      expect(revoked.data.revoked_at).not.toBeNull();

      await api.deleteMcpToken(created.data.id);
      expect((await api.listMcpTokens()).data.map((token) => token.id)).not.toContain(
        created.data.id
      );
    } finally {
      await api.deleteMcpTokenSafe(created.data.id);
    }
  });

  test('creating a token without a name is rejected', async ({ api }) => {
    const response = await api.createMcpTokenRaw('');
    expect([400, 422]).toContain(response.status);
  });

  test('revoking an unknown token returns 404', async ({ api }) => {
    const revoke = await api.put('mcp-tokens/999999999/revoke');
    await expectStatus(revoke, 404);
  });
});
