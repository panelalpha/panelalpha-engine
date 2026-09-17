import { expect, test } from '@/fixtures/test-options';
import { HAND_WRITTEN_MCP_TOOLS, readMcpToolCatalogue } from '@/helpers/mcp-helpers';

/**
 * The catalogue reader itself — no engine involved.
 *
 * `mcp-tool-catalogue.spec.ts` compares the engine's tool listing against this
 * parse, so a parse that silently returned nothing would turn that test green
 * for the wrong reason. This pins the parse instead.
 */
test.describe('MCP tool catalogue parsing', () => {
  test('the engine catalogue parses into named entries', () => {
    const catalogue = readMcpToolCatalogue();
    test.skip(
      catalogue === null,
      'core/app/Mcp/tool-names.php is not readable from this checkout.'
    );

    expect(catalogue!.length).toBeGreaterThan(100);

    for (const entry of catalogue!) {
      expect(entry.tool, `${entry.verb} ${entry.apiPath}`).toMatch(/^[a-z][a-z0-9_]*$/);
      expect(entry.verb).toMatch(/^(GET|POST|PUT|PATCH|DELETE)$/);
      expect(entry.apiPath.startsWith('/'), entry.apiPath).toBe(true);
    }
  });

  test('tool names are unique', () => {
    const catalogue = readMcpToolCatalogue();
    test.skip(
      catalogue === null,
      'core/app/Mcp/tool-names.php is not readable from this checkout.'
    );

    const seen = new Map<string, string>();
    const collisions: string[] = [];
    for (const entry of catalogue!) {
      const route = `${entry.verb} ${entry.apiPath}`;
      const previous = seen.get(entry.tool);
      if (previous) {
        collisions.push(`${entry.tool}: ${previous} and ${route}`);
      }
      seen.set(entry.tool, route);
    }

    // One name, one operation: an assistant has no other way to tell them apart.
    expect(collisions, 'the same tool name is mapped to two routes').toEqual([]);
  });

  test('a GET is read-only and its placeholders are extracted', () => {
    const catalogue = readMcpToolCatalogue();
    test.skip(
      catalogue === null,
      'core/app/Mcp/tool-names.php is not readable from this checkout.'
    );

    for (const entry of catalogue!) {
      expect(entry.readOnly, `${entry.verb} ${entry.apiPath}`).toBe(entry.verb === 'GET');
    }

    const domainList = catalogue!.find((entry) => entry.tool === 'domain_list');
    expect(domainList).toBeTruthy();
    expect(domainList!.params).toEqual(['username']);
    expect(domainList!.readOnly).toBe(true);

    const projectDelete = catalogue!.find((entry) => entry.tool === 'project_delete');
    expect(projectDelete!.readOnly).toBe(false);

    const systemInfo = catalogue!.find((entry) => entry.tool === 'system_info');
    expect(systemInfo!.params).toEqual([]);
  });

  test('the hand-written tools are not in the route map', () => {
    const catalogue = readMcpToolCatalogue();
    test.skip(
      catalogue === null,
      'core/app/Mcp/tool-names.php is not readable from this checkout.'
    );

    // They have no API route, which is why the parity spec adds them by hand.
    const names = new Set(catalogue!.map((entry) => entry.tool));
    for (const tool of HAND_WRITTEN_MCP_TOOLS) {
      expect(names.has(tool), `${tool} is derived from a route after all`).toBe(false);
    }
  });
});
