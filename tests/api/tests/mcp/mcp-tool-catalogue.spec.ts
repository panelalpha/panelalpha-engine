import { expect, test } from '@/fixtures/test-options';
import { uniqueId } from '@/helpers/random';
import { skipUnless } from '@/helpers/test-helpers';
import { HAND_WRITTEN_MCP_TOOLS, McpSession, readMcpToolCatalogue } from '@/helpers/mcp-helpers';

/**
 * `tools/list` against `core/app/Mcp/tool-names.php`.
 *
 * The map is the client-facing contract: an assistant picks a tool by name, and
 * a renamed or dropped tool breaks saved prompts and allow-lists silently —
 * nothing in the REST suite notices, because the route it wraps still answers.
 * Reading the map from the repository rather than copying it here is what makes
 * this a parity check instead of a second list to keep in sync.
 *
 * Exposure is configurable (`MCP_TOOLSETS`, `MCP_TOOLS`, `MCP_PERMISSION_MODE`),
 * so an operator can legitimately serve a subset. Set
 * `MCP_EXPECT_FULL_CATALOGUE=false` on such an engine; a stock install serves
 * everything and a gap there is a bug.
 */
const expectFullCatalogue = process.env.MCP_EXPECT_FULL_CATALOGUE?.trim().toLowerCase() !== 'false';

test.describe('MCP tool catalogue', () => {
  test('every exposed tool is named in the engine catalogue', async ({
    api,
    anonymousRequest,
    settings,
  }) => {
    const catalogue = readMcpToolCatalogue();
    skipUnless(catalogue, 'core/app/Mcp/tool-names.php is not readable from this checkout.');

    const created = await api.createMcpToken(uniqueId('mcp-catalogue-'));
    const token = created.data.plain_text_token;
    skipUnless(token, 'createMcpToken did not return a plaintext token.');

    try {
      const session = await McpSession.open(anonymousRequest, settings.apiBaseUrl, token);
      const exposed = (await session.listTools()).map((tool) => tool.name);

      expect(exposed.length, 'the server exposed no tools at all').toBeGreaterThan(0);

      const duplicates = exposed.filter((name, index) => exposed.indexOf(name) !== index);
      expect(duplicates, 'the same tool name was listed twice').toEqual([]);

      const known = new Set<string>([
        ...catalogue.map((entry) => entry.tool),
        ...HAND_WRITTEN_MCP_TOOLS,
      ]);
      const unknown = exposed.filter((name) => !known.has(name)).sort();
      expect(
        unknown,
        'tools served under names the engine catalogue does not define — the map and the server have drifted'
      ).toEqual([]);
    } finally {
      await api.deleteMcpTokenSafe(created.data.id);
    }
  });

  test('a stock engine exposes the whole catalogue', async ({
    api,
    anonymousRequest,
    settings,
  }) => {
    test.skip(
      !expectFullCatalogue,
      'MCP_EXPECT_FULL_CATALOGUE=false — this engine serves a narrowed tool set.'
    );

    const catalogue = readMcpToolCatalogue();
    skipUnless(catalogue, 'core/app/Mcp/tool-names.php is not readable from this checkout.');

    const created = await api.createMcpToken(uniqueId('mcp-catalogue-full-'));
    const token = created.data.plain_text_token;
    skipUnless(token, 'createMcpToken did not return a plaintext token.');

    try {
      const session = await McpSession.open(anonymousRequest, settings.apiBaseUrl, token);
      const exposed = new Set((await session.listTools()).map((tool) => tool.name));

      const missing = [...catalogue.map((entry) => entry.tool), ...HAND_WRITTEN_MCP_TOOLS]
        .filter((name) => !exposed.has(name))
        .sort();

      expect(
        missing,
        'named in tool-names.php but not served — either the tool is unregistered or the map is stale'
      ).toEqual([]);
    } finally {
      await api.deleteMcpTokenSafe(created.data.id);
    }
  });

  test('every exposed tool declares a description and an input schema', async ({
    api,
    anonymousRequest,
    settings,
  }) => {
    const created = await api.createMcpToken(uniqueId('mcp-catalogue-shape-'));
    const token = created.data.plain_text_token;
    skipUnless(token, 'createMcpToken did not return a plaintext token.');

    try {
      const session = await McpSession.open(anonymousRequest, settings.apiBaseUrl, token);
      const tools = await session.listTools();

      // An assistant picks a tool from this metadata alone. A tool with no
      // description, or no schema for its arguments, is unusable in practice
      // even though it lists and calls fine.
      const undescribed = tools
        .filter((tool) => (tool.description ?? '').trim().length === 0)
        .map((tool) => tool.name)
        .sort();
      expect(undescribed, 'tools served without a description').toEqual([]);

      const schemaless = tools
        .filter((tool) => tool.inputSchema === undefined || tool.inputSchema === null)
        .map((tool) => tool.name)
        .sort();
      expect(schemaless, 'tools served without an input schema').toEqual([]);
    } finally {
      await api.deleteMcpTokenSafe(created.data.id);
    }
  });
});
