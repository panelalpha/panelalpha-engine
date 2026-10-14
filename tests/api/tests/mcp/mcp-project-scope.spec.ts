import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import {
  McpSession,
  expectMcpToolError,
  mcpResultText,
  type McpPayload,
} from '@/helpers/mcp-helpers';
import { uniqueId } from '@/helpers/random';
import { skipUnless } from '@/helpers/test-helpers';

interface ProjectListItem {
  username: string;
}

interface TaskListItem {
  id: number;
  username: string | null;
}

/** The API body a successful tool wraps under `data`. */
function toolData(payload: McpPayload): unknown {
  const parsed: unknown = JSON.parse(mcpResultText(payload));
  if (parsed !== null && typeof parsed === 'object' && 'data' in parsed) {
    return parsed.data;
  }
  return parsed;
}

/**
 * A token created with `projects` reaches those projects and nothing else,
 * over both MCP and REST, and the scope can be moved under it.
 */
test.describe('MCP project scope', () => {
  test('a project-scoped token reaches only its project', async ({
    api,
    anonymousRequest,
    settings,
    userFactory,
  }) => {
    // Two real projects, their deploy tasks, and a scoped session.
    test.setTimeout(Timeouts.deploy * 2);

    const alice = await userFactory.createSimpleUser();
    const bob = await userFactory.createSimpleUser();

    const created = await api.createMcpToken(uniqueId('mcp-scope-'), [alice.username]);
    const token = created.data.plain_text_token;
    skipUnless(token, 'createMcpToken did not return a plaintext token.');

    try {
      const session = await McpSession.open(anonymousRequest, settings.apiBaseUrl, token);

      await session.callOk('project_get', { name: alice.username });
      expectMcpToolError(await session.call('project_get', { name: bob.username }), 'project_get');

      const projects = toolData(await session.callOk('project_list')) as {
        data: ProjectListItem[];
      };
      expect(projects.data.map((project) => project.username)).toEqual([alice.username]);

      const version = (await api.getDomainPhpVersion(alice.domain)).data;
      await session.callOk('domain_php_version_set', { domain: alice.domain, version });
      expectMcpToolError(
        await session.call('domain_php_version_set', { domain: bob.domain, version }),
        'domain_php_version_set'
      );

      expect(await session.describeTool('firewall_status')).toBeUndefined();
      expectMcpToolError(await session.call('firewall_status'), 'firewall_status');
      await session.callOk('system_info');

      const aliceTasks = await api.listTasks({ username: alice.username });
      const bobTasks = await api.listTasks({ username: bob.username });
      skipUnless(bobTasks.data[0], 'the Bob project has no task to refuse.');
      await session.callOk('task_get', { id: aliceTasks.data[0].id });
      expectMcpToolError(await session.call('task_get', { id: bobTasks.data[0].id }), 'task_get');

      const listed = toolData(await session.callOk('task_list')) as { data: TaskListItem[] };
      expect(
        listed.data.every((task) => task.username === alice.username),
        'the task list leaked another project'
      ).toBe(true);

      const logs = await api.listMcpActivityLogs({ project: alice.username });
      expect(logs.data.length, 'no activity log entry carries the project').toBeGreaterThan(0);
      expect(
        logs.data.every((row) => row.project === alice.username),
        'the activity log filter returned another project'
      ).toBe(true);

      await api.updateMcpTokenProjects(created.data.id, [bob.username]);
      expectMcpToolError(
        await session.call('project_get', { name: alice.username }),
        'project_get'
      );
      await session.callOk('project_get', { name: bob.username });
    } finally {
      await api.deleteMcpTokenSafe(created.data.id);
    }
  });
});
