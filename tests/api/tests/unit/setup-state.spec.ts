import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { expect, test } from '@/fixtures/test-options';
import type { SetupTestData } from '@/types/user.types';
import {
  DEFAULT_LEASE_TTL_MS,
  LEASE_HEARTBEAT_INTERVAL_MS,
  UNREFRESHED_LEASE_TTL_MS,
  startLeaseHeartbeat,
  currentRunId,
  isLeaseActive,
  leaseTtlMs,
  readLeases,
  readSetupState,
  refreshLease,
  runIdentity,
  pickSetupUser,
  setupStatePath,
  setupStateUsers,
  setupUsernames,
  writeLease,
  writeSetupState,
} from '@/fixtures/helper/setup-state';

/** The run lease and the state file's identity fields, against a temporary directory. */

const HOUR = 60 * 60 * 1000;

function tempDir(): string {
  return fs.mkdtempSync(path.join(os.tmpdir(), 'leases-'));
}

test.describe('run lease', () => {
  test('a heartbeat inside the TTL is active, one beyond it is not', () => {
    const identity = runIdentity(1_000_000);
    const lease = { ...identity, usernames: [], heartbeatIntervalMs: LEASE_HEARTBEAT_INTERVAL_MS };

    expect(isLeaseActive(lease, 1_000_000 + 20 * 60_000)).toBe(true);
    expect(isLeaseActive(lease, 1_000_000 + DEFAULT_LEASE_TTL_MS + 1)).toBe(false);
    expect(isLeaseActive(lease, 1_000_000 + HOUR, HOUR)).toBe(false);
  });

  test('a lease nobody refreshes keeps the three-hour window', () => {
    const lease = { ...runIdentity(1_000_000), usernames: [] };

    expect(isLeaseActive(lease, 1_000_000 + 2 * HOUR)).toBe(true);
    expect(isLeaseActive(lease, 1_000_000 + UNREFRESHED_LEASE_TTL_MS + 1)).toBe(false);
  });

  test('the TTL defaults to six heartbeat intervals and can be overridden', () => {
    expect(LEASE_HEARTBEAT_INTERVAL_MS).toBe(5 * 60_000);
    expect(leaseTtlMs({})).toBe(30 * 60_000);
    expect(leaseTtlMs({})).toBe(6 * LEASE_HEARTBEAT_INTERVAL_MS);
    expect(leaseTtlMs({ TEST_LEASE_TTL_MS: '60000' })).toBe(60_000);
    expect(leaseTtlMs({ TEST_LEASE_TTL_MS: 'soon' })).toBe(30 * 60_000);
  });

  test('the heartbeat refreshes at start, on every tick and on stop, and is unref-ed', () => {
    const calls: number[] = [];
    let tick: (() => void) | undefined;
    let interval: number | undefined;
    let unrefed = false;
    let cleared = false;
    const heartbeat = startLeaseHeartbeat({
      intervalMs: 1_000,
      refresh: (ms) => calls.push(ms),
      setInterval: (callback, ms) => {
        tick = callback;
        interval = ms;
        return {
          unref: () => {
            unrefed = true;
          },
        };
      },
      clearInterval: () => {
        cleared = true;
      },
    });
    expect(calls).toHaveLength(1);
    expect(interval).toBe(1_000);
    expect(unrefed).toBe(true);

    tick?.();
    tick?.();
    expect(calls).toHaveLength(3);

    heartbeat.stop();
    expect(cleared).toBe(true);
    expect(calls).toHaveLength(4);
  });

  test('a throwing refresh does not stop the heartbeat', () => {
    let tick: (() => void) | undefined;
    let attempts = 0;
    startLeaseHeartbeat({
      refresh: () => {
        attempts++;
        throw new Error('disk full');
      },
      setInterval: (callback) => {
        tick = callback;
        return {};
      },
    });
    tick?.();
    expect(attempts).toBe(2);
  });

  test('refreshLease marks the lease as heartbeat-driven and writeLease keeps the mark', () => {
    const dir = tempDir();
    const identity = runIdentity(1_000);
    writeLease(['pwaaaaaaaa'], dir, identity);
    refreshLease(dir, 2_000, identity.runId, LEASE_HEARTBEAT_INTERVAL_MS);
    writeLease(['pwbbbbbbbb'], dir, { ...identity, heartbeatAt: 3_000 });

    const [lease] = readLeases(dir);
    expect(lease.heartbeatIntervalMs).toBe(LEASE_HEARTBEAT_INTERVAL_MS);
    expect(lease.usernames.sort()).toEqual(['pwaaaaaaaa', 'pwbbbbbbbb']);
    expect(lease.heartbeatAt).toBe(3_000);
  });

  test('TEST_RUN_ID names the run, otherwise one id is generated and kept', () => {
    expect(currentRunId({ TEST_RUN_ID: ' ci-42 ' })).toBe('ci-42');
    expect(currentRunId({})).toBe(currentRunId({}));
  });

  test('writeLease stores one file per run and keeps usernames across writes', () => {
    const dir = tempDir();
    const identity = runIdentity(5_000);
    writeLease(['pwaaaaaaaa'], dir, identity);
    writeLease(['pwbbbbbbbb', 'pwaaaaaaaa'], dir, identity);
    writeLease([], dir, { ...identity, runId: 'other' });

    const leases = readLeases(dir);
    expect(leases).toHaveLength(2);
    const own = leases.find((lease) => lease.runId === identity.runId);
    expect(own?.usernames).toEqual(['pwaaaaaaaa', 'pwbbbbbbbb']);
    expect(own?.checkout).toBe(process.cwd());
    expect(own?.pid).toBe(process.pid);
  });

  test('refreshLease moves the heartbeat and leaves ownership alone', () => {
    const dir = tempDir();
    const identity = runIdentity(5_000);
    writeLease(['pwaaaaaaaa'], dir, identity);

    const lease = refreshLease(dir, 9_000, identity.runId);

    expect(lease?.heartbeatAt).toBe(9_000);
    expect(lease?.startedAt).toBe(identity.startedAt);
    expect(readLeases(dir)[0].usernames).toEqual(['pwaaaaaaaa']);
  });

  test('readLeases skips unreadable files and a missing directory', () => {
    const dir = tempDir();
    fs.writeFileSync(path.join(dir, 'broken.json'), '{nope', 'utf-8');
    fs.writeFileSync(path.join(dir, 'odd.json'), '{"runId":1}', 'utf-8');

    expect(readLeases(dir)).toEqual([]);
    expect(readLeases(path.join(dir, 'missing'))).toEqual([]);
  });
});

test.describe('setup state file', () => {
  test('a state file from before run ids existed is still read', () => {
    const file = setupStatePath('unit-compat');
    const previous = process.env.TEST_ENV;
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(
      file,
      JSON.stringify({
        user: { username: 'pwoldstate', url: 'https://pwoldstate.example/' },
        webserver: 'nginx',
        createdAt: 1,
      }),
      'utf-8'
    );
    process.env.TEST_ENV = 'unit-compat';
    try {
      const state = readSetupState();
      expect(state?.user.username).toBe('pwoldstate');
      expect(state?.runId).toBeUndefined();
    } finally {
      fs.rmSync(file, { force: true });
      if (previous === undefined) {
        delete process.env.TEST_ENV;
      } else {
        process.env.TEST_ENV = previous;
      }
    }
  });
});

test.describe('one setup user per worker', () => {
  const user = (username: string) =>
    ({ username, url: `https://${username}.example/` }) as SetupTestData;
  const single = { user: user('pwone') };
  const many = { user: user('pwone'), users: [user('pwone'), user('pwtwo'), user('pwthree')] };

  test('a state without a list is one user, whatever the worker', () => {
    expect(setupStateUsers(single).map((entry) => entry.username)).toEqual(['pwone']);
    for (const index of [0, 1, 5, undefined]) {
      expect(pickSetupUser(single, index).username).toBe('pwone');
    }
  });

  test('a worker gets the user at its parallel index, and a stray index wraps around', () => {
    expect(pickSetupUser(many, 0).username).toBe('pwone');
    expect(pickSetupUser(many, 1).username).toBe('pwtwo');
    expect(pickSetupUser(many, 2).username).toBe('pwthree');
    expect(pickSetupUser(many, 3).username).toBe('pwone');
    expect(pickSetupUser(many, undefined).username).toBe('pwone');
    expect(pickSetupUser(many, -1).username).toBe('pwone');
  });

  test('every user is listed for the leak check', () => {
    expect(setupUsernames(many)).toEqual(['pwone', 'pwtwo', 'pwthree']);
    expect(setupUsernames(single)).toEqual(['pwone']);
    expect(setupUsernames(undefined)).toEqual([]);
  });

  test('the list survives the state file, and a one-user state stays in the old format', () => {
    const previous = process.env.TEST_ENV;
    process.env.TEST_ENV = 'unit-array';
    const file = setupStatePath('unit-array');
    try {
      writeSetupState({ ...many, webserver: 'nginx', createdAt: 1 });
      expect(readSetupState()?.users?.map((entry) => entry.username)).toEqual([
        'pwone',
        'pwtwo',
        'pwthree',
      ]);

      writeSetupState({ ...single, webserver: 'nginx', createdAt: 1 });
      const stored = JSON.parse(fs.readFileSync(file, 'utf-8')) as Record<string, unknown>;
      expect(Object.keys(stored).sort()).toEqual(['createdAt', 'user', 'webserver']);
    } finally {
      fs.rmSync(file, { force: true });
      if (previous === undefined) {
        delete process.env.TEST_ENV;
      } else {
        process.env.TEST_ENV = previous;
      }
    }
  });

  test('a lease lists every user', () => {
    const dir = tempDir();
    const identity = runIdentity(5_000);
    writeLease(setupUsernames(many), dir, identity);
    expect(readLeases(dir)[0].usernames).toEqual(['pwone', 'pwtwo', 'pwthree']);
  });
});
