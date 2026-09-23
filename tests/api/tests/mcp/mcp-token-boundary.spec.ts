import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { uniqueId } from '@/helpers/random';
import { skipUnless } from '@/helpers/test-helpers';
import { McpSession } from '@/helpers/mcp-helpers';
import { McpHttpClient } from '@/clients/mcp-http';

/**
 * An MCP token is not a full-access API token. The CLI (`pae configure` /
 * `mcp:token:create`) mints the same kind: MCP surface on, REST surface off,
 * unless the operator asked for `--api`.
 */
test.describe('MCP token surface', () => {
  test('an MCP token is refused at the REST API', async ({ api, anonymousRequest, settings }) => {
    const created = await api.createMcpToken(uniqueId('mcp-rest-'));
    const token = created.data.plain_text_token;
    skipUnless(token, 'createMcpToken did not return a plaintext token.');

    try {
      const rest = await anonymousRequest.get('projects', {
        headers: { Authorization: `Bearer ${token}` },
      });
      expectOneOf(rest.status(), [401, 403], 'an MCP token was allowed to list projects over REST');

      const client = new McpHttpClient(anonymousRequest, settings.apiBaseUrl);
      const check = await client.check(token);
      expect(check.status(), 'the same token must still work on /mcp').toBe(200);

      const session = await McpSession.open(anonymousRequest, settings.apiBaseUrl, token);
      await session.callOk('project_list_summary');
    } finally {
      await api.deleteMcpTokenSafe(created.data.id);
    }
  });
});
