import { expect, test } from '@/fixtures/test-options';
import {
  ENGINE_SFTP_PORT,
  deleteFirewallRulesQuietly,
  listFirewallRules,
  randomTestNetAddress,
  randomTestPort,
  requireFirewall,
} from '@/helpers/firewall-helpers';
import { McpSession, expectMcpToolError, mcpResultText } from '@/helpers/mcp-helpers';
import { uniqueId } from '@/helpers/random';
import { skipUnless } from '@/helpers/test-helpers';
import type { FirewallRule } from '@/types';

const COMMENT = 'api-test mcp';

/** What a firewall tool answered, as the rule the REST API would have returned. */
function ruleOf(text: string): FirewallRule {
  // The tool hands back the API's body, itself under `data`.
  let value: unknown = JSON.parse(text);
  while (value !== null && typeof value === 'object' && 'data' in value && !('id' in value)) {
    value = value.data;
  }
  return value as FirewallRule;
}

/**
 * The firewall's write tools, through MCP. The read-only sweep covers status and
 * listing; this is the path an assistant takes to change the firewall. Rules
 * match only a documentation range (RFC 5737).
 */
test.describe('Firewall through MCP', () => {
  const created = new Set<string>();

  test.beforeEach(async ({ api }) => {
    await requireFirewall(api);
  });

  test.afterEach(async ({ api }) => {
    // Also by comment: a tool answer that failed to parse never yields an id.
    for (const rule of await listFirewallRules(api)) {
      if (rule.comment?.startsWith(COMMENT)) {
        created.add(rule.id);
      }
    }
    await deleteFirewallRulesQuietly(api, created);
    created.clear();
  });

  test('a rule is added, edited and deleted with the firewall tools', async ({
    api,
    anonymousRequest,
    settings,
  }) => {
    const token = (await api.createMcpToken(uniqueId('mcp-firewall-'))).data;
    skipUnless(token.plain_text_token, 'createMcpToken did not return a plaintext token.');

    try {
      const session = await McpSession.open(
        anonymousRequest,
        settings.apiBaseUrl,
        token.plain_text_token
      );
      const source = randomTestNetAddress();
      const port = randomTestPort();

      const added = ruleOf(
        mcpResultText(
          await session.callOk('firewall_rule_create', {
            action: 'allow',
            protocol: 'tcp',
            port,
            source,
            comment: COMMENT,
          })
        )
      );
      created.add(added.id);
      expect(added).toMatchObject({ action: 'allow', port, source, managed: false });
      expect((await listFirewallRules(api)).map((rule) => rule.id)).toContain(added.id);

      const edited = ruleOf(
        mcpResultText(
          await session.callOk('firewall_rule_update', {
            id: added.id,
            comment: `${COMMENT}, edited`,
          })
        )
      );
      expect(edited.id).toBe(added.id);
      expect(edited.comment).toBe(`${COMMENT}, edited`);

      await session.callOk('firewall_rule_delete', { id: added.id });
      created.delete(added.id);
      expect((await listFirewallRules(api)).map((rule) => rule.id)).not.toContain(added.id);

      // Refusals arrive as tool errors, not as an empty success.
      expectMcpToolError(
        await session.call('firewall_rule_create', { action: 'allow', direction: 'out' }),
        'firewall_rule_create'
      );
      const sftp = (await listFirewallRules(api)).find(
        (rule) => rule.managed && rule.port === ENGINE_SFTP_PORT && rule.protocol === 'tcp'
      );
      expect(sftp, 'the engine keeps 2222/tcp open').toBeDefined();
      expectMcpToolError(
        await session.call('firewall_rule_delete', { id: sftp!.id }),
        'firewall_rule_delete'
      );
    } finally {
      await api.deleteMcpTokenSafe(token.id);
    }
  });
});
