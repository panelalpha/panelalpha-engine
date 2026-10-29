import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import {
  McpSession,
  expectMcpToolError,
  mcpResultText,
  type McpPayload,
} from '@/helpers/mcp-helpers';
import { expectStatus } from '@/helpers/expect-status';
import { uniqueId } from '@/helpers/random';
import { skipUnless } from '@/helpers/test-helpers';

/** What a token refused for its project scope says: the 403 from EnsureTokenMayUseApi. */
const OUT_OF_SCOPE = 'limited to projects';

/** The refusal of a creation that leaves the destination name to the engine. */
const MUST_NAME = 'must name the project it creates';

/** A 4xx answered by the controller, which means the scope check let the call through. */
const ANSWERED_BY_CONTROLLER = /"status":(409|422)/;

/** The API text of a failed call, from a tool result or a plain error body. */
function failureText(payload: McpPayload): string {
  return mcpResultText(payload) || JSON.stringify(payload.result ?? payload.error);
}

/** Asserts a call failed because of the token's project scope, not for another reason. */
function expectScopeRefusal(payload: McpPayload, name: string): void {
  expectMcpToolError(payload, name);
  expect(failureText(payload), `${name} was refused for the token's scope`).toContain(OUT_OF_SCOPE);
}

/** Asserts a call cleared the scope check and was answered by the controller. */
function expectLetThrough(payload: McpPayload, name: string): void {
  expectMcpToolError(payload, name);
  const text = failureText(payload);
  expect(text, `${name} was refused for the token's scope`).not.toContain(OUT_OF_SCOPE);
  expect(text, `${name} reached the controller`).toMatch(ANSWERED_BY_CONTROLLER);
}

/**
 * A clone or a staging copy brings a project into existence. A project-scoped
 * token may only create one under a name it lists, and may not leave the name
 * to the engine: the name the engine picks cannot be on a list fixed at mint
 * time. Unscoped tokens are unchanged.
 */
test.describe('project scope: clone and staging', () => {
  test('an assistant scoped to a project cannot create another one', async ({
    api,
    anonymousRequest,
    settings,
    userFactory,
  }) => {
    test.setTimeout(Timeouts.deploy * 2);

    const alice = await userFactory.createSimpleUser();
    const bob = await userFactory.createSimpleUser();

    const created = await api.createMcpToken(uniqueId('mcp-create-'), [alice.username]);
    const token = created.data.plain_text_token;
    skipUnless(token, 'createMcpToken did not return a plaintext token.');

    try {
      const session = await McpSession.open(anonymousRequest, settings.apiBaseUrl, token);

      for (const tool of ['project_clone', 'project_staging']) {
        await test.step(`${tool} is held to the scope`, async () => {
          expectScopeRefusal(
            await session.call(tool, { name: alice.username, new_name: bob.username }),
            tool
          );
          const omitted = await session.call(tool, { name: alice.username });
          expectScopeRefusal(omitted, `${tool} without new_name`);
          const text = failureText(omitted);
          expect(text, 'the refusal says a name is needed').toContain(MUST_NAME);
          expect(text, 'the refusal names the tool argument').toMatch(/field\W+new_name\b/);
          expect(text, 'the refusal names no REST field').not.toContain('new_username');
        });

        await test.step(`${tool} reaches a name the scope lists`, async () => {
          expectLetThrough(
            await session.call(tool, { name: alice.username, new_name: alice.username }),
            tool
          );
        });
      }
    } finally {
      await api.deleteMcpTokenSafe(created.data.id);
    }
  });

  test('an API token scoped to a project cannot create another one over REST', async ({
    api,
    anonymousRequest,
    hostExec,
    userFactory,
  }) => {
    test.setTimeout(Timeouts.deploy * 2);
    skipUnless(hostExec, 'pae-artisan is not reachable from this runner.');

    const alice = await userFactory.createSimpleUser();
    const bob = await userFactory.createSimpleUser();

    const minted = await hostExec.pae([
      'mcp:token:create',
      uniqueId('scope-api-'),
      '--short',
      '--api',
      '--no-register',
    ]);
    expect(minted.exitCode, minted.stderr).toBe(0);
    const plain = minted.stdout
      .split('\n')
      .map((line) => line.trim())
      .find((line) => /^\d+\|\S+$/.test(line));
    skipUnless(plain, 'mcp:token:create did not print "<id>|<token>".');

    const [id, token] = plain.split('|');
    await api.updateMcpTokenProjects(Number(id), [alice.username]);

    const post = (action: string, data: Record<string, unknown>) =>
      anonymousRequest.post(`projects/${alice.username}/${action}`, {
        headers: { Authorization: `Bearer ${token}` },
        data,
      });

    try {
      for (const action of ['clone', 'staging']) {
        await test.step(`${action} into another project is 403`, async () => {
          const response = await post(action, { new_username: bob.username });
          await expectStatus(response, 403, `${action} into a project outside the scope`);
          expect(JSON.stringify(await response.json())).toContain(OUT_OF_SCOPE);
        });

        await test.step(`${action} without a destination name is 403`, async () => {
          const response = await post(action, {});
          await expectStatus(response, 403, `${action} with a name the engine would pick`);
          const body = JSON.stringify(await response.json());
          expect(body).toContain(MUST_NAME);
          expect(body, 'the refusal names the field to send').toMatch(/field\W+new_username\b/);
        });

        await test.step(`${action} into a name the scope lists is not refused`, async () => {
          const response = await post(action, { new_username: alice.username });
          await expectStatus(response, [409, 422], `${action} into a project inside the scope`);
          expect(JSON.stringify(await response.json())).not.toContain(OUT_OF_SCOPE);
        });
      }
    } finally {
      await hostExec.pae(['mcp:token:delete', id]);
    }
  });
});
