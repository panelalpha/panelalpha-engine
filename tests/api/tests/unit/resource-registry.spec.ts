import { appendFileSync, existsSync, mkdtempSync, rmSync, utimesSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

import { expect, test } from '@/fixtures/test-options';
import type { Lease } from '@/fixtures/helper/setup-state';
import {
  openResources,
  preservePolicy,
  readResourceEvents,
  recoverOrphanedResources,
  type RecoveryEngine,
  recordResource,
  RESOURCE_REGISTRY_DIR,
  resourceFile,
} from '@/helpers/resource-registry';

test.describe('resource-registry', () => {
  let dir: string;
  test.beforeEach(() => {
    dir = mkdtempSync(path.join(tmpdir(), 'resources-'));
  });
  test.afterEach(() => {
    rmSync(dir, { recursive: true, force: true });
  });

  test('a unit spec records into its own registry, never the one a live run recovers from', () => {
    const runId = `unit-isolation-${Math.random().toString(16).slice(2, 10)}`;
    recordResource({ key: 'pwunit1', event: 'create-failed', taskId: 23 }, { runId });

    expect(existsSync(resourceFile(runId, RESOURCE_REGISTRY_DIR))).toBe(false);
    expect(readResourceEvents(resourceFile(runId)).map((event) => event.key)).toEqual(['pwunit1']);
  });

  test('appends one JSON line per event under the run id', () => {
    recordResource({ key: 'tst1', event: 'creating' }, { runId: 'run-a', dir });
    recordResource({ key: 'tst1', event: 'created', taskId: 7 }, { runId: 'run-a', dir });

    const events = readResourceEvents(resourceFile('run-a', dir));
    expect(events.map((e) => e.event)).toEqual(['creating', 'created']);
    expect(events[1]).toMatchObject({ runId: 'run-a', kind: 'project', key: 'tst1', taskId: 7 });
    expect(typeof events[0].at).toBe('string');
    expect(events[0].test).toContain('appends one JSON line');
  });

  test('redacts and shortens the detail', () => {
    recordResource(
      {
        key: 'tst1',
        event: 'create-failed',
        detail: `password: hunter2hunter2 ${'x'.repeat(1000)}`,
      },
      { runId: 'run-b', dir }
    );
    const [event] = readResourceEvents(resourceFile('run-b', dir));
    expect(event.detail).not.toContain('hunter2hunter2');
    expect(event.detail!.length).toBeLessThanOrEqual(301);
  });

  test('never throws when the directory cannot be written', () => {
    const blocker = path.join(dir, 'file');
    appendFileSync(blocker, 'x');
    expect(() =>
      recordResource({ key: 'tst1', event: 'creating' }, { runId: 'run-c', dir: blocker })
    ).not.toThrow();
  });

  test('reads a missing file as empty and skips a torn line', () => {
    const file = resourceFile('run-d', dir);
    expect(readResourceEvents(file)).toEqual([]);
    recordResource({ key: 'tst1', event: 'creating' }, { runId: 'run-d', dir });
    appendFileSync(file, '{"key":"tst2","ev');
    expect(readResourceEvents(file)).toHaveLength(1);
  });

  test('openResources lists keys whose last event is not deleted or refused', () => {
    const at = '2026-01-01T00:00:00Z';
    const base = { at, runId: 'r', kind: 'project' as const };
    const events = [
      { ...base, key: 'a', event: 'creating' as const },
      { ...base, key: 'a', event: 'deleted' as const },
      { ...base, key: 'b', event: 'creating' as const },
      { ...base, key: 'b', event: 'refused' as const },
      { ...base, key: 'c', event: 'deleting' as const },
      { ...base, key: 'd', event: 'deleted' as const },
      { ...base, key: 'd', event: 'creating' as const },
      { ...base, key: 'e', event: 'delete-failed' as const },
      { ...base, key: 'f', event: 'preserved' as const },
    ];
    expect(openResources(events)).toEqual(['c', 'd', 'e', 'f']);
  });

  test('preservePolicy keeps only with PRESERVE_ON_FAILURE=1 outside CI', () => {
    expect(preservePolicy({})).toEqual({ keep: false, ignoredInCi: false });
    expect(preservePolicy({ PRESERVE_ON_FAILURE: '1' })).toEqual({
      keep: true,
      ignoredInCi: false,
    });
    expect(preservePolicy({ PRESERVE_ON_FAILURE: '0' }).keep).toBe(false);
    expect(preservePolicy({ PRESERVE_ON_FAILURE: '1', CI: 'true' })).toEqual({
      keep: false,
      ignoredInCi: true,
    });
    expect(preservePolicy({ CI: 'true' })).toEqual({ keep: false, ignoredInCi: false });
  });
});

test.describe('recoverOrphanedResources', () => {
  const NOW = Date.parse('2026-10-08T12:00:00Z');
  const MIN = 60_000;
  let dir: string;
  test.beforeEach(() => {
    dir = mkdtempSync(path.join(tmpdir(), 'recovery-'));
  });
  test.afterEach(() => {
    rmSync(dir, { recursive: true, force: true });
  });

  function lease(runId: string, heartbeatAgoMs: number, usernames: string[] = []): Lease {
    return {
      runId,
      host: 'h',
      checkout: '/c',
      pid: 1,
      startedAt: NOW - heartbeatAgoMs,
      heartbeatAt: NOW - heartbeatAgoMs,
      usernames,
      heartbeatIntervalMs: 5 * MIN,
    };
  }

  function engine(gone: string[] = []): RecoveryEngine & { removed: string[] } {
    const removed: string[] = [];
    return {
      removed,
      exists: (name) => Promise.resolve(!gone.includes(name)),
      remove: (name) => {
        removed.push(name);
        return Promise.resolve();
      },
    };
  }

  function seed(
    runId: string,
    events: [string, 'creating' | 'created' | 'deleted' | 'preserved' | 'refused'][]
  ): void {
    for (const [key, event] of events) {
      recordResource({ key, event }, { runId, dir });
    }
  }

  test('deletes the open projects of an inactive run and records recovered', async () => {
    seed('old', [
      ['pw1', 'created'],
      ['pw2', 'created'],
      ['pw2', 'deleted'],
      ['pw3', 'creating'],
      ['pw4', 'refused'],
    ]);
    const fake = engine();

    const result = await recoverOrphanedResources(fake, {
      dir,
      currentRunId: 'now',
      leases: [lease('old', 40 * MIN)],
      now: NOW,
    });

    expect(fake.removed.sort()).toEqual(['pw1', 'pw3']);
    expect(result.recovered.map((r) => r.username).sort()).toEqual(['pw1', 'pw3']);
    expect(openResources(readResourceEvents(resourceFile('old', dir)))).toEqual([]);
    const last = readResourceEvents(resourceFile('old', dir)).at(-1);
    expect(last?.event).toBe('recovered');

    const again = engine();
    await recoverOrphanedResources(again, {
      dir,
      currentRunId: 'now',
      leases: [lease('old', 40 * MIN)],
      now: NOW,
    });
    expect(again.removed).toEqual([]);
  });

  test('skips a run whose lease is active, the current run and the setup users', async () => {
    seed('live', [['pw1', 'created']]);
    seed('now', [['pw2', 'created']]);
    seed('old', [
      ['pw3', 'created'],
      ['pw4', 'created'],
      ['pw5', 'created'],
    ]);
    const fake = engine();

    const result = await recoverOrphanedResources(fake, {
      dir,
      currentRunId: 'now',
      keepUsernames: ['PW3'],
      leases: [lease('live', 10 * MIN, ['pw5']), lease('old', 40 * MIN)],
      now: NOW,
    });

    expect(fake.removed).toEqual(['pw4']);
    expect(result.kept.map((k) => `${k.username}:${k.reason}`).sort()).toEqual([
      'pw1:its run lease is still active',
      'pw3:current setup user',
      'pw5:owned by an active run lease',
    ]);
  });

  test('a project that is already gone is recorded without a delete', async () => {
    seed('old', [['pw1', 'created']]);
    const fake = engine(['pw1']);

    const result = await recoverOrphanedResources(fake, {
      dir,
      currentRunId: 'now',
      leases: [lease('old', 40 * MIN)],
      now: NOW,
    });

    expect(fake.removed).toEqual([]);
    expect(result.recovered).toEqual([{ username: 'pw1', runId: 'old', outcome: 'gone' }]);
    expect(openResources(readResourceEvents(resourceFile('old', dir)))).toEqual([]);
  });

  test('a failed delete keeps the project open for the next run', async () => {
    seed('old', [['pw1', 'created']]);
    const result = await recoverOrphanedResources(
      {
        exists: () => Promise.resolve(true),
        remove: () => Promise.reject(new Error('500')),
      },
      { dir, currentRunId: 'now', leases: [lease('old', 40 * MIN)], now: NOW }
    );

    expect(result.recovered).toEqual([]);
    expect(result.kept[0].reason).toContain('recovery failed: 500');
    expect(openResources(readResourceEvents(resourceFile('old', dir)))).toEqual(['pw1']);
  });

  test('keeps a preserved project', async () => {
    seed('old', [['pw1', 'preserved']]);
    const fake = engine();
    const result = await recoverOrphanedResources(fake, {
      dir,
      currentRunId: 'now',
      leases: [lease('old', 40 * MIN)],
      now: NOW,
    });
    expect(fake.removed).toEqual([]);
    expect(result.kept[0].reason).toBe('preserved on purpose');
  });

  test('a run without a lease is recovered only after its file has been idle', async () => {
    seed('nolease', [['pw1', 'created']]);
    const file = resourceFile('nolease', dir);

    const fresh = engine();
    await recoverOrphanedResources(fresh, {
      dir,
      currentRunId: 'now',
      leases: [],
      now: Date.now(),
    });
    expect(fresh.removed).toEqual([]);

    const old = new Date(Date.now() - 4 * 60 * 60 * 1000);
    utimesSync(file, old, old);
    const idle = engine();
    await recoverOrphanedResources(idle, { dir, currentRunId: 'now', leases: [], now: Date.now() });
    expect(idle.removed).toEqual(['pw1']);
  });
});
