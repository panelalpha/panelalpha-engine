import { expect, test } from '@/fixtures/test-options';
import {
  collectTaskDiagnostics,
  formatTaskDiagnostics,
  formatTaskDiagnosticsReport,
  type TaskDiagnosticsApi,
} from '@/helpers/task-diagnostics';

interface Raw {
  status: number;
  body: unknown;
}

const line = (id: number, msg: string, extra: Record<string, unknown> = {}) => ({
  id,
  log: JSON.stringify({ msg, level: 'info', stage: 'build', ...extra }),
});

const keptTail = [
  line(1, 'Deploy started'),
  line(2, 'Last 2 lines of the deploy log (the rollback deletes it):', { deploy_log_tail: true }),
  line(3, 'npm ERR! build failed', { deploy_log_tail: true, level: 'error' }),
];

function fakeApi(overrides: Partial<TaskDiagnosticsApi> = {}): TaskDiagnosticsApi {
  return {
    getTaskRaw: () =>
      Promise.resolve<Raw>({
        status: 200,
        body: {
          data: {
            id: 23,
            status: 'failed',
            username: 'pwtest',
            details: { error: 'Build failed: exit code 1' },
            queued_at: '2026-01-01T10:00:00Z',
            started_at: '2026-01-01T10:00:02Z',
            failed_at: '2026-01-01T10:01:00Z',
            logs: keptTail,
            next_after_id: 3,
          },
        },
      }),
    listTaskLogsRaw: () => Promise.resolve<Raw>({ status: 200, body: { data: [], meta: {} } }),
    ...overrides,
  };
}

test.describe('collectTaskDiagnostics', () => {
  test('a failed task yields its error and the kept deploy log tail', async () => {
    const d = await collectTaskDiagnostics(fakeApi(), 23);

    expect(d).toMatchObject({
      taskId: 23,
      username: 'pwtest',
      status: 'failed',
      error: 'Build failed: exit code 1',
      logTailKept: true,
      timings: { queuedAt: '2026-01-01T10:00:00Z', finishedAt: '2026-01-01T10:01:00Z' },
    });
    const text = formatTaskDiagnostics(d);
    expect(text).toContain('error: Build failed: exit code 1');
    expect(text).toContain('[error][build] npm ERR! build failed');
    expect(text).toContain('user: pwtest');
    expect(formatTaskDiagnosticsReport(d)).toContain('status=failed');
  });

  test('pages the logs endpoint past the snapshot', async () => {
    const cursors: (number | undefined)[] = [];
    const api = fakeApi({
      listTaskLogsRaw: (_id, params) => {
        cursors.push(params?.after_id);
        return Promise.resolve<Raw>({
          status: 200,
          body:
            cursors.length === 1
              ? { data: [line(4, 'more')], meta: { next_after_id: 4 } }
              : { data: [] },
        });
      },
    });
    const d = await collectTaskDiagnostics(api, 23);

    expect(cursors).toEqual([3, 4]);
    expect(d.logTail.at(-1)).toBe('[info][build] more');
  });

  test('a failing logs endpoint becomes a note', async () => {
    const api = fakeApi({ listTaskLogsRaw: () => Promise.reject(new Error('socket hang up')) });
    const d = await collectTaskDiagnostics(api, 23);

    expect(d.notes.join()).toContain('socket hang up');
    expect(d.error).toBe('Build failed: exit code 1');
  });

  test('a 404 task is a note, not a throw', async () => {
    const api = fakeApi({
      getTaskRaw: () => Promise.resolve<Raw>({ status: 404, body: {} }),
      listTaskLogsRaw: () => Promise.resolve<Raw>({ status: 404, body: {} }),
    });
    const d = await collectTaskDiagnostics(api, 23);

    expect(d.notes.join()).toContain('answered 404');
    expect(formatTaskDiagnostics(d)).toContain('(no deploy log tail kept on the task)');
  });

  test('the time budget is respected when the Engine hangs', async () => {
    const hang = () => new Promise<Raw>(() => undefined);
    const started = Date.now();
    const d = await collectTaskDiagnostics(
      fakeApi({ getTaskRaw: hang, listTaskLogsRaw: hang }),
      23,
      { budgetMs: 100 }
    );

    expect(Date.now() - started).toBeLessThan(2_000);
    expect(d.notes.join()).toContain('time budget');
  });

  test('without a kept tail the project deploy log is read when it exists', async () => {
    const api = fakeApi({
      getTaskRaw: () =>
        Promise.resolve<Raw>({
          status: 200,
          body: { data: { id: 23, status: 'failed', logs: [] } },
        }),
      getDeployLogRaw: () =>
        Promise.resolve<Raw>({
          status: 200,
          body: { data: { lines: [{ msg: 'git clone failed', level: 'error' }] } },
        }),
    });
    const d = await collectTaskDiagnostics(api, 23, { username: 'pwtest' });

    expect(d.logTailKept).toBe(false);
    expect(formatTaskDiagnostics(d)).toContain('[error] git clone failed');
  });

  test('a secret inside a log line is redacted', async () => {
    process.env.API_TOKEN = 'tok_PLANTEDDIAGTOKEN99';
    try {
      const api = fakeApi({
        getTaskRaw: () =>
          Promise.resolve<Raw>({
            status: 200,
            body: {
              data: {
                id: 23,
                status: 'failed',
                details: { error: 'clone https://u:pw12345@git.test/r failed' },
                logs: [line(1, 'sent Authorization: Bearer tok_PLANTEDDIAGTOKEN99')],
              },
            },
          }),
      });
      const d = await collectTaskDiagnostics(api, 23);

      for (const text of [formatTaskDiagnostics(d), formatTaskDiagnosticsReport(d)]) {
        expect(text).not.toContain('PLANTEDDIAGTOKEN');
        expect(text).not.toContain('pw12345');
      }
    } finally {
      delete process.env.API_TOKEN;
    }
  });
});
