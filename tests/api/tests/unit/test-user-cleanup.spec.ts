import { expect, test } from '@/fixtures/test-options';
import type { EngineApi } from '@/clients/engine-api';
import type { UserFactory } from '@/test-data/factories/user.factory';
import type { Lease } from '@/fixtures/helper/setup-state';
import { cleanupStaleTestUsers, recordMatchesUsername } from '@/helpers/test-user-cleanup';

/** Which `pw*` projects the stale cleanup may delete, against a fake Engine. */

const HOUR = 60 * 60 * 1000;
const NOW = Date.parse('2026-10-08T12:00:00Z');
const TTL = 3 * HOUR;

function ago(ms: number): string {
  return new Date(NOW - ms).toISOString();
}

function fakes(projects: { username: string; created_at?: string }[]): {
  api: EngineApi;
  factory: UserFactory;
  deleted: string[];
} {
  const deleted: string[] = [];
  const api = {
    listAllUsers: () =>
      Promise.resolve({ data: projects.map((p) => ({ domain: `${p.username}.test`, ...p })) }),
  } as unknown as EngineApi;
  const factory = {
    deleteUser: (username: string) => {
      deleted.push(username);
      return Promise.resolve();
    },
  } as unknown as UserFactory;
  return { api, factory, deleted };
}

function lease(heartbeatAgo: number, usernames: string[]): Lease {
  return {
    runId: 'other-run',
    host: 'ci-1',
    checkout: '/srv/other/tests/api',
    pid: 1,
    startedAt: NOW - heartbeatAgo,
    heartbeatAt: NOW - heartbeatAgo,
    usernames,
  };
}

test('keeps the current setup user, an active lease user, a young project and one of unknown age', async () => {
  const { api, factory, deleted } = fakes([
    { username: 'pw00000001', created_at: ago(10 * HOUR) },
    { username: 'pw00000002', created_at: ago(10 * HOUR) },
    { username: 'pw00000003', created_at: ago(HOUR) },
    { username: 'pw00000004' },
    { username: 'pw00000005', created_at: 'not a date' },
    { username: 'shop', created_at: ago(10 * HOUR) },
  ]);

  const result = await cleanupStaleTestUsers(api, factory, {
    keepUsernames: ['pw00000001'],
    leases: [lease(HOUR, ['pw00000002'])],
    now: NOW,
    ttlMs: TTL,
  });

  expect(deleted).toEqual([]);
  expect(result.removed).toEqual([]);
  expect(Object.fromEntries(result.kept.map((k) => [k.username, k.reason]))).toMatchObject({
    pw00000001: expect.stringContaining('current setup user'),
    pw00000002: expect.stringContaining('active run lease'),
    pw00000003: expect.stringContaining('younger than the minimum age'),
    pw00000004: expect.stringContaining('age unknown'),
    pw00000005: expect.stringContaining('age unknown'),
  });
  expect(result.kept.map((k) => k.username)).not.toContain('shop');
});

test('deletes an old orphan, including one whose only lease has expired', async () => {
  const { api, factory, deleted } = fakes([
    { username: 'pw0000000a', created_at: ago(10 * HOUR) },
    { username: 'pw0000000b', created_at: ago(10 * HOUR) },
  ]);

  const result = await cleanupStaleTestUsers(api, factory, {
    leases: [lease(5 * HOUR, ['pw0000000b'])],
    now: NOW,
    ttlMs: TTL,
  });

  expect(deleted).toEqual(['pw0000000a', 'pw0000000b']);
  expect(result.removed).toEqual(deleted);
  expect(result.kept).toEqual([]);
});

test('a failed list touches nothing and says so', async () => {
  const api = {
    listAllUsers: () => Promise.reject(new Error('boom')),
  } as unknown as EngineApi;
  const { factory, deleted } = fakes([]);

  const result = await cleanupStaleTestUsers(api, factory, { leases: [], now: NOW });

  expect(result).toEqual({ removed: [], kept: [], listFailed: true });
  expect(deleted).toEqual([]);
});

test('a failed delete is reported as kept', async () => {
  const { api } = fakes([{ username: 'pw0000000c', created_at: ago(10 * HOUR) }]);
  const factory = {
    deleteUser: () => Promise.reject(new Error('busy')),
  } as unknown as UserFactory;

  const result = await cleanupStaleTestUsers(api, factory, { leases: [], now: NOW, ttlMs: TTL });

  expect(result.removed).toEqual([]);
  expect(result.kept[0].reason).toContain('delete failed: busy');
});

test.describe('recordMatchesUsername', () => {
  test('matches the record of that user only', () => {
    expect(recordMatchesUsername({ data: { username: 'PWabc' } }, 'pwabc')).toBe(true);
    expect(recordMatchesUsername({ data: { username: 'other' } }, 'pwabc')).toBe(false);
    expect(recordMatchesUsername({}, 'pwabc')).toBe(false);
    expect(recordMatchesUsername(null, 'pwabc')).toBe(false);
  });
});
