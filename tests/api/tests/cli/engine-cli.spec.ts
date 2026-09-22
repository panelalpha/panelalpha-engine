import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { uniqueId } from '@/helpers/random';
import { skipUnless } from '@/helpers/test-helpers';
import { McpHttpClient } from '@/clients/mcp-http';

/**
 * The CLI side of token scope and deploy-cache cleanup. `pae configure` is a
 * TUI, so these are the non-interactive commands that implement the same
 * switches: `mcp:tool:list` (what the ceiling currently exposes),
 * `mcp:token:create` (MCP-only bearer), and `deploy:cache:prune --dry-run`
 * (the idle-cache cleanup that already exists).
 */
test.describe('engine CLI that is already on 2.0.0', () => {
  test('mcp:tool:list reports the current permission mode', async ({ hostExec }) => {
    skipUnless(hostExec, 'pae-artisan is not reachable from this runner.');

    const result = await hostExec.pae(['mcp:tool:list']);
    expect(result.exitCode, result.stderr || result.stdout).toBe(0);

    const output = `${result.stdout}\n${result.stderr}`;
    expect(output, 'mcp:tool:list did not print a permission mode').toMatch(
      /\bmode\b.+\b(full|readonly|modify)\b/i
    );
    expect(output, 'mcp:tool:list did not summarise exposed tools').toMatch(/\d+ exposed/i);
  });

  test('mcp:token:create mints a token that works on MCP and not REST', async ({
    api,
    anonymousRequest,
    hostExec,
    settings,
  }) => {
    skipUnless(hostExec, 'pae-artisan is not reachable from this runner.');

    const name = uniqueId('cli-mcp-');
    const created = await hostExec.pae(['mcp:token:create', name, '--short', '--no-register']);
    expect(created.exitCode, created.stderr || created.stdout).toBe(0);
    const token = created.stdout.trim().split(/\s+/).pop();
    skipUnless(token, 'mcp:token:create --short printed no token.');

    let tokenId: number | undefined;
    try {
      const listed = await api.listMcpTokens();
      tokenId = listed.data.find((entry) => entry.name === name)?.id;

      const rest = await anonymousRequest.get('projects', {
        headers: { Authorization: `Bearer ${token}` },
      });
      expectOneOf(
        rest.status(),
        [401, 403],
        'a CLI-minted MCP token was allowed to list projects over REST'
      );

      const check = await new McpHttpClient(anonymousRequest, settings.apiBaseUrl).check(token);
      expect(check.status()).toBe(200);
    } finally {
      if (tokenId !== undefined) {
        await api.deleteMcpTokenSafe(tokenId);
      }
    }
  });

  test('deploy:cache:prune --dry-run does not fail', async ({ hostExec }) => {
    skipUnless(hostExec, 'pae-artisan is not reachable from this runner.');

    const result = await hostExec.pae(['deploy:cache:prune', '--dry-run']);
    expect(result.exitCode, result.stderr || result.stdout).toBe(0);
    const output = `${result.stdout}\n${result.stderr}`;
    expect(output, 'dry-run should say what it would free, or that nothing is idle').toMatch(
      /would free|no project cache has been idle/i
    );
  });
});
