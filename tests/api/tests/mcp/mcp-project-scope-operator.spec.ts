import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import {
  McpSession,
  expectMcpToolError,
  mcpResultText,
  type McpPayload,
} from '@/helpers/mcp-helpers';
import { rand, uniqueId } from '@/helpers/random';
import { skipUnless } from '@/helpers/test-helpers';

/** What a token refused for its scope says: the 403 from EnsureTokenMayUseApi. */
const OUT_OF_SCOPE = 'limited to projects';

/** Asserts a tool call failed because the token's project scope refused it. */
function expectScopeRefusal(payload: McpPayload, name: string): string {
  expectMcpToolError(payload, name);
  const text = mcpResultText(payload) || JSON.stringify(payload.result ?? payload.error);
  expect(text, `${name} was refused for the token's scope`).toContain(OUT_OF_SCOPE);
  return text;
}

/**
 * A project-scoped token reaches its project, not what the operator owns
 * there: limits, suspension, and any other project named in a body or query
 * field (an inspected source, a push diff target).
 */
test.describe('MCP project scope: operator-owned operations', () => {
  test('a project-scoped token cannot reach another project or the operator’s settings', async ({
    api,
    anonymousRequest,
    settings,
    userFactory,
  }) => {
    test.setTimeout(Timeouts.deploy * 2);

    const alice = await userFactory.createSimpleUser();
    const bob = await userFactory.createSimpleUser();

    const created = await api.createMcpToken(uniqueId('mcp-operator-'), [alice.username]);
    const token = created.data.plain_text_token;
    skipUnless(token, 'createMcpToken did not return a plaintext token.');

    try {
      const session = await McpSession.open(anonymousRequest, settings.apiBaseUrl, token);

      await test.step('source_inspect does not read a project outside the scope', async () => {
        expectScopeRefusal(
          await session.call('source_inspect', { source: bob.username, type: 'project' }),
          'source_inspect'
        );
        expectScopeRefusal(
          await session.call('source_inspect', { source: bob.username }),
          'source_inspect (bare username)'
        );
      });

      await test.step('project_update changes the email but not a limit', async () => {
        const before = (await api.getUser(alice.username)).data;
        const email = `${rand('scope')}@example.com`;

        await session.callOk('project_update', { name: alice.username, email });
        expect((await api.getUser(alice.username)).data.email, 'the email was changed').toBe(email);

        const refused = expectScopeRefusal(
          await session.call('project_update', { name: alice.username, disk_space_limit: 1 }),
          'project_update'
        );
        expect(refused, 'the refusal names the operator-owned field').toContain('disk_space_limit');
        expect(
          (await api.getUser(alice.username)).data.details.disk_space_limit,
          'the disk limit is unchanged'
        ).toEqual(before.details.disk_space_limit);
      });

      await test.step('suspend and unsuspend are not offered and cannot be called', async () => {
        for (const tool of ['project_suspend', 'project_unsuspend']) {
          expect(await session.describeTool(tool), `${tool} is not offered`).toBeUndefined();
          expectMcpToolError(await session.call(tool, { name: alice.username }), tool);
        }
        expect((await api.getUser(alice.username)).data.status, 'the project still runs').toBe(
          'active'
        );
      });

      await test.step('a push diff against another project is refused', async () => {
        expectScopeRefusal(
          await session.call('project_push_diff', { name: alice.username, target: bob.username }),
          'project_push_diff'
        );
      });
    } finally {
      await api.deleteMcpTokenSafe(created.data.id);
    }
  });
});
