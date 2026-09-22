import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import { uniqueId } from '@/helpers/random';
import { skipUnless } from '@/helpers/test-helpers';
import {
  McpSession,
  isMcpToolError,
  mcpResultText,
  readMcpToolCatalogue,
  type McpToolDescriptor,
} from '@/helpers/mcp-helpers';

/**
 * Calls every read-only MCP tool the engine exposes and fails on the ones that
 * do not work.
 *
 * The REST suite already covers these endpoints, so what is under test here is
 * the MCP translation layer on top of them: argument mapping, the response
 * shaping, and the registration itself. A broken tool answers `tools/call` with
 * HTTP 200 and `result.isError: true`, which is why this has to look inside the
 * result rather than at the status.
 *
 * Which tools to call is derived, not listed: the read-only set comes from the
 * engine's own `tool-names.php` (a GET is read-only), and the arguments come
 * from each tool's advertised `inputSchema`. A tool whose required arguments
 * this spec cannot supply is reported rather than silently dropped.
 */

/** Values this spec can supply for a required argument, by argument name. */
function smokeArguments(username: string, domain: string): Record<string, unknown> {
  return {
    name: username,
    username: username,
    project: username,
    domain,
  };
}

/**
 * Read-only by verb, but not safe or meaningful to call blind.
 *
 * Each entry needs a reason: this list is the escape hatch, and an unexplained
 * name in it is how coverage quietly shrinks.
 */
const NOT_SMOKE_CALLABLE: Record<string, string> = {
  app_sso_login: 'mints a single-use SSO token — a read by verb, a side effect in practice',
  file_download: 'returns file contents; needs a path fixture and can be large',
  csf_ui_credentials: 'returns live firewall UI credentials',
  modsec_audit_log_download: 'streams a whole audit log file',
  task_log_stream: 'a streaming response, not a single JSON-RPC answer',
};

/**
 * Refusals that mean "not this kind of project", not "this tool is broken".
 *
 * The shared setup project is a plain WordPress install. Container, app-user,
 * deploy-log and git tools are only defined for a DinD or git-backed project,
 * and refusing there is the tool working — `tests/deploy/` is where those are
 * exercised against a project that has containers. Matching on the engine's own
 * message keeps this narrow: any other error is still a failure.
 */
const NOT_APPLICABLE_TO_PROJECT = [
  /only available for dind users/i,
  /no git repository at path/i,
  // git_deploy_hook_show on a project that never had a hook set up: an honest
  // 404, and creating one is a write this smoke must not make.
  /has no deploy hook/i,
];

function isNotApplicable(detail: string): boolean {
  return NOT_APPLICABLE_TO_PROJECT.some((pattern) => pattern.test(detail));
}

function requiredArgs(tool: McpToolDescriptor): string[] {
  const schema = tool.inputSchema;
  if (schema === undefined || schema === null) {
    return [];
  }
  const required = (schema as { required?: unknown }).required;
  return Array.isArray(required)
    ? required.filter((key): key is string => typeof key === 'string')
    : [];
}

test.describe('MCP read-only tools', () => {
  test('every read-only tool answers without reporting an error', async ({
    api,
    anonymousRequest,
    settings,
    setupUser,
  }) => {
    // Dozens of sequential tool calls against a live engine; the default
    // per-test budget is sized for one operation, not a sweep.
    test.setTimeout(Timeouts.default * 3);

    const catalogue = readMcpToolCatalogue();
    skipUnless(catalogue, 'core/app/Mcp/tool-names.php is not readable from this checkout.');

    const created = await api.createMcpToken(uniqueId('mcp-readonly-'));
    const token = created.data.plain_text_token;
    skipUnless(token, 'createMcpToken did not return a plaintext token.');

    try {
      const session = await McpSession.open(anonymousRequest, settings.apiBaseUrl, token);
      const exposed = new Map((await session.listTools()).map((tool) => [tool.name, tool]));
      const context = smokeArguments(setupUser.username, setupUser.domain);

      const failures: string[] = [];
      const uncallable: string[] = [];
      const notApplicable: string[] = [];
      let called = 0;

      for (const entry of catalogue.filter((item) => item.readOnly)) {
        const tool = exposed.get(entry.tool);
        if (!tool) {
          // Whether a tool is exposed at all is mcp-tool-catalogue.spec.ts's job.
          continue;
        }
        if (entry.tool in NOT_SMOKE_CALLABLE) {
          continue;
        }

        const required = requiredArgs(tool);
        const unsupplied = required.filter((key) => !(key in context));
        if (unsupplied.length > 0) {
          uncallable.push(`${entry.tool} (needs ${unsupplied.join(', ')})`);
          continue;
        }

        const args = Object.fromEntries(required.map((key) => [key, context[key]]));
        const payload = await session.call(entry.tool, args);
        called += 1;
        if (isMcpToolError(payload)) {
          const detail = mcpResultText(payload) || JSON.stringify(payload.error);
          if (isNotApplicable(detail)) {
            notApplicable.push(entry.tool);
          } else {
            failures.push(`${entry.tool}(${JSON.stringify(args)}) -> ${detail.slice(0, 300)}`);
          }
        }
      }

      test.info().annotations.push({
        type: 'mcp-readonly-smoke',
        description:
          `called ${called}, answered ${called - notApplicable.length}, ` +
          `not applicable to this project: ${notApplicable.length}, ` +
          `not callable without a fixture: ${uncallable.length}`,
      });
      if (notApplicable.length > 0) {
        test.info().annotations.push({
          type: 'mcp-readonly-not-applicable',
          description: notApplicable.join(', '),
        });
      }
      if (uncallable.length > 0) {
        test.info().annotations.push({
          type: 'mcp-readonly-uncovered',
          description: uncallable.join(', '),
        });
      }

      expect(failures, `read-only MCP tools reported errors:\n${failures.join('\n')}`).toEqual([]);
      expect(
        called - notApplicable.length,
        'no read-only tool actually answered — the smoke covered nothing'
      ).toBeGreaterThan(0);
    } finally {
      await api.deleteMcpTokenSafe(created.data.id);
    }
  });

  test('a read-only tool refuses a project that does not exist', async ({
    api,
    anonymousRequest,
    settings,
  }) => {
    const created = await api.createMcpToken(uniqueId('mcp-readonly-404-'));
    const token = created.data.plain_text_token;
    skipUnless(token, 'createMcpToken did not return a plaintext token.');

    try {
      const session = await McpSession.open(anonymousRequest, settings.apiBaseUrl, token);

      // The REST layer answers 404 here. What matters at this layer is that the
      // 404 survives translation instead of arriving as an empty success.
      for (const tool of ['project_get', 'domain_list', 'cron_job_list']) {
        const payload = await session.call(tool, { name: 'nosuchproject999' });
        expect(
          isMcpToolError(payload),
          `${tool} answered for a project that does not exist: ${mcpResultText(payload)}`
        ).toBe(true);
      }
    } finally {
      await api.deleteMcpTokenSafe(created.data.id);
    }
  });
});
