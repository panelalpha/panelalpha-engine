import { expect, test } from '@/fixtures/test-options';
import { rand } from '@/helpers/random';

test.describe('MCP activity logs', () => {
  test('the activity log listing is paginated', async ({ api }) => {
    const page = await api.listMcpActivityLogs();
    expect(Array.isArray(page.data)).toBe(true);
  });

  test('an activity log entry can be created and filtered by token name', async ({ api }) => {
    const tokenName = rand('mcplog');
    const created = await api.createMcpActivityLog({
      token_name: tokenName,
      tool_name: 'ping',
      status: 'success',
      input: { hello: 'world' },
    });

    expect(created.data.token_name).toBe(tokenName);
    expect(created.data.tool_name).toBe('ping');
    expect(created.data.status).toBe('success');

    const filtered = await api.listMcpActivityLogs({ tokenName });
    expect(filtered.data.some((entry) => entry.id === created.data.id)).toBe(true);
  });

  test('the token name filter matches part of the name, ignoring case', async ({ api }) => {
    const tokenName = rand('McpLogSearch');
    const created = await api.createMcpActivityLog({
      token_name: tokenName,
      tool_name: 'ping',
      status: 'success',
    });

    const filtered = await api.listMcpActivityLogs({
      tokenName: tokenName.slice(2).toUpperCase(),
    });
    expect(filtered.data.map((entry) => entry.id)).toContain(created.data.id);
  });

  test('the token ids filter keeps only the calls of those tokens', async ({ api }) => {
    const tokenName = rand('mcplogids');
    const created = await api.createMcpActivityLog({
      token_name: tokenName,
      tool_name: 'ping',
      status: 'success',
    });
    const writerId = created.data.token_id;
    if (writerId === null) {
      test.skip(true, 'the API token that wrote the row is not a personal access token');
      return;
    }

    const mine = await api.listMcpActivityLogs({ tokenIds: [writerId], tokenName });
    expect(mine.data.map((entry) => entry.id)).toEqual([created.data.id]);

    const others = await api.listMcpActivityLogs({ tokenIds: [writerId + 1_000_000], tokenName });
    expect(others.data).toEqual([]);
  });

  test('an incomplete activity log payload is rejected', async ({ api }) => {
    const response = await api.createMcpActivityLogRaw({
      token_name: '',
      tool_name: '',
      status: 'success',
    });
    expect([400, 422]).toContain(response.status);
  });
});
