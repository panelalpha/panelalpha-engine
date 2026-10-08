// eslint-disable-next-line no-restricted-imports
import { test as base } from '@playwright/test';
import { expect, test } from '@/fixtures/test-options';
import {
  collectEngineContext,
  filterLaravelLog,
  hostClockOffsetMs,
  type ContextApi,
} from '@/helpers/failure-context';
import type { HostExec } from '@/helpers/host-exec';

const SECRET = 'hunter2planted';

interface Raw {
  status: number;
  body: unknown;
}

function fakeApi(
  overrides: Partial<Record<keyof ContextApi, (...a: never[]) => Promise<Raw>>> = {}
) {
  const calls: string[] = [];
  const api = {
    getUserRaw: (name: string) => {
      calls.push(`user ${name}`);
      return Promise.resolve<Raw>({
        status: 200,
        body: { data: { username: name, password: SECRET } },
      });
    },
    listContainersRaw: (name: string) => {
      calls.push(`containers ${name}`);
      return Promise.resolve<Raw>({
        status: 200,
        body: { data: [{ Service: 'web' }, { Service: 'db' }] },
      });
    },
    getContainerLogsRaw: (name: string, service: string) => {
      calls.push(`logs ${name}/${service}`);
      return Promise.resolve<Raw>({
        status: 200,
        body: { data: `line one\nAuthorization: Bearer abc.def.ghi\npassword=${SECRET}` },
      });
    },
    ...overrides,
  };
  return { api: api, calls };
}

const window = { fromMs: Date.UTC(2026, 9, 8, 12, 0, 0), toMs: Date.UTC(2026, 9, 8, 12, 1, 0) };

test.describe('filterLaravelLog', () => {
  const log = [
    '[2026-10-08 11:00:00] local.ERROR: too early',
    '[2026-10-08 12:00:10] local.ERROR: inside',
    '#0 stack frame of inside',
    '[2026-10-08 12:00:50] local.INFO: also inside',
    '[2026-10-08 12:30:00] local.ERROR: too late',
    '#0 stack frame of too late',
  ].join('\n');

  test('keeps entries in the window with their continuation lines', () => {
    const out = filterLaravelLog(log, window);
    expect(out).toContain('inside');
    expect(out).toContain('stack frame of inside');
    expect(out).toContain('also inside');
    expect(out).not.toContain('too early');
    expect(out).not.toContain('too late');
    expect(out).not.toContain('stack frame of too late');
  });

  test('pads the window by a few seconds', () => {
    const edge = '[2026-10-08 11:59:57] local.ERROR: just before';
    expect(filterLaravelLog(edge, window)).toContain('just before');
    expect(filterLaravelLog(edge, window, { padMs: 0 })).toBe('');
  });

  test('shifts by the host clock offset and keeps only the last lines', () => {
    const local = '[2026-10-08 14:00:10] local.ERROR: host local time';
    expect(filterLaravelLog(local, window)).toBe('');
    expect(filterLaravelLog(local, window, { clockOffsetMs: 2 * 3_600_000 })).toContain(
      'host local'
    );
    const many = Array.from({ length: 10 }, (_, i) => `[2026-10-08 12:00:${10 + i}] n${i}`).join(
      '\n'
    );
    expect(filterLaravelLog(many, window, { maxLines: 2 }).split('\n')).toHaveLength(2);
  });

  test('derives a quarter-hour offset from the host clock', () => {
    const now = Date.UTC(2026, 9, 8, 12, 0, 3);
    expect(hostClockOffsetMs('2026-10-08 14:00:05', now)).toBe(2 * 3_600_000);
    expect(hostClockOffsetMs('2026-10-08 12:00:05', now)).toBe(0);
    expect(hostClockOffsetMs('garbage', now)).toBe(0);
  });
});

test.describe('collectEngineContext', () => {
  test('collects service logs, setup user and host log, redacted', async () => {
    const { api, calls } = fakeApi();
    const hostExec = {
      available: true,
      mode: 'local',
      paeBin: 'pae',
      pae: () => Promise.reject(new Error('unused')),
      run: () =>
        Promise.resolve({
          exitCode: 0,
          stdout: '@NOW 2026-10-08 12:01:00\n[2026-10-08 12:00:20] local.ERROR: boom\n',
          stderr: '',
        }),
    } as unknown as HostExec;
    const text = await collectEngineContext({
      api,
      hostExec,
      projects: ['alpha'],
      setupUser: 'shared',
      window,
      now: () => window.toMs,
    });
    expect(calls).toEqual(
      expect.arrayContaining(['containers alpha', 'logs alpha/web', 'logs alpha/db', 'user shared'])
    );
    expect(text).toContain('service log alpha/web');
    expect(text).toContain('local.ERROR: boom');
    expect(text).not.toContain(SECRET);
    expect(text).not.toContain('abc.def.ghi');
  });

  test('turns failures into notes and notes a missing host', async () => {
    const { api } = fakeApi({
      listContainersRaw: () => Promise.reject(new Error('containers exploded')),
      getUserRaw: () => Promise.resolve({ status: 500, body: { message: 'nope' } }),
    });
    const text = await collectEngineContext({
      api,
      hostExec: null,
      projects: ['alpha'],
      setupUser: 'shared',
      window,
    });
    expect(text).toContain('unavailable: ');
    expect(text).toContain('containers exploded');
    expect(text).toContain('GET projects/shared -> 500');
    expect(text).toContain('host log not available');
  });

  test('stops at the shared deadline and stays within the byte cap', async () => {
    const never = () => new Promise<Raw>(() => undefined);
    const { api } = fakeApi({ listContainersRaw: never });
    const started = Date.now();
    const text = await collectEngineContext({
      api,
      hostExec: null,
      projects: ['slow'],
      window,
      budgetMs: 150,
    });
    expect(Date.now() - started).toBeLessThan(2_000);
    expect(text).toContain('cut: the context budget ran out');

    const huge = fakeApi({
      getContainerLogsRaw: () =>
        Promise.resolve<Raw>({ status: 200, body: { data: 'x'.repeat(50_000) } }),
    });
    const capped = await collectEngineContext({
      api: huge.api,
      hostExec: null,
      projects: ['a', 'b', 'c'],
      window,
      maxBytes: 5_000,
    });
    expect(capped.length).toBeLessThanOrEqual(5_000 + 60);
  });
});

// Playwright sets fixtures up in dependency order and tears them down in reverse. The same
// shape as test-options: the context fixture depends on the factory, so it ends first.
const events: string[] = [];
const chain = base.extend<{ factory: string; engineProbe: void }>({
  // eslint-disable-next-line no-empty-pattern
  factory: async ({}, use) => {
    events.push('factory up');
    await use('factory');
    events.push('factory down (deletes projects)');
  },
  engineProbe: [
    async ({ factory }, use) => {
      events.push(`context up after ${factory}`);
      await use();
      events.push('context down (reads projects)');
    },
    { auto: true },
  ],
});

base.describe('fixture ordering', () => {
  base.describe.configure({ mode: 'serial' });

  chain('a test using the chain', () => {
    events.push('body');
  });

  base('context teardown ran before the factory teardown', () => {
    expect(events).toEqual([
      'factory up',
      'context up after factory',
      'body',
      'context down (reads projects)',
      'factory down (deletes projects)',
    ]);
  });
});
