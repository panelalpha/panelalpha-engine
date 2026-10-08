import { randomUUID } from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import type { SetupTestData } from '@/types/user.types';

/**
 * State handed from the `setup` project to every other project.
 *
 * Replaces the previous `playwright-relay` cache. The mechanism is the same one
 * Playwright uses for `storageState`: the setup project writes a JSON file, the
 * dependent projects read it. It is a plain file you can `cat` when a run
 * misbehaves, and it survives `--no-deps` reruns of a single spec.
 */
export interface RunIdentity {
  /** `TEST_RUN_ID` when set, else one id generated per process. */
  runId: string;
  host: string;
  /** Absolute path of this tests/api checkout. */
  checkout: string;
  pid: number;
  /** Epoch millis when this process first asked for its identity. */
  startedAt: number;
  /** Epoch millis of the moment this identity was stamped into the file. */
  heartbeatAt: number;
}

/**
 * Identity fields are optional: a state file written before run ids existed must
 * stay readable.
 */
export type StampedRunIdentity = Partial<RunIdentity>;

export interface SetupState extends StampedRunIdentity {
  /** Shared test user provisioned once per suite run, with WordPress installed. */
  user: SetupTestData;
  /**
   * One shared user per parallel worker when the run is split (`TEST_PARALLEL`); `user` is
   * the first of them. Absent in a single-worker run and in files written before it existed.
   */
  users?: SetupTestData[];
  /** Webserver slug active when the user was provisioned (nginx, litespeed, …). */
  webserver: string;
  /** Epoch millis — used only for operator diagnostics. */
  createdAt: number;
}

export interface SetupDindState extends StampedRunIdentity {
  username: string;
  domain: string;
  url: string;
  git_repo: string;
  createdAt: number;
}

const PLAYWRIGHT_DIR = path.resolve(process.cwd(), '.playwright');
const STATE_DIR = path.join(PLAYWRIGHT_DIR, 'state');

/**
 * How often the runner (`scripts/run-tests.ts`) refreshes the run's lease. The runner
 * outlives both parallel phases, so the heartbeat keeps going for the whole run.
 */
export const LEASE_HEARTBEAT_INTERVAL_MS = 5 * 60 * 1000;

/**
 * A lease is active while its heartbeat is newer than this. With a live heartbeat the
 * freshest a healthy run's stamp can be is one interval old, so six intervals (30 min)
 * means five consecutive refreshes would have to be missed before a live run looks
 * dead, while a crashed run is recognised in half an hour instead of three hours.
 * Override with `TEST_LEASE_TTL_MS`.
 */
export const DEFAULT_LEASE_TTL_MS = LEASE_HEARTBEAT_INTERVAL_MS * 6;

/**
 * A lease nobody refreshes (a bare `playwright test`, or a lease written before the
 * heartbeat existed) stamps its heartbeat once near the start and then runs for as
 * long as the suite takes, so it keeps the former three-hour window.
 */
export const UNREFRESHED_LEASE_TTL_MS = 3 * 60 * 60 * 1000;

export function leaseTtlMs(env: NodeJS.ProcessEnv = process.env): number {
  const parsed = Number(env.TEST_LEASE_TTL_MS);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : DEFAULT_LEASE_TTL_MS;
}

/**
 * The window that applies to `lease`: the configured TTL when the lease is kept alive
 * by a heartbeat, else at least the three-hour window. An explicit `TEST_LEASE_TTL_MS`
 * always wins.
 */
export function leaseTtlFor(lease: Lease, env: NodeJS.ProcessEnv = process.env): number {
  const explicit = Number(env.TEST_LEASE_TTL_MS);
  if (Number.isFinite(explicit) && explicit > 0) {
    return explicit;
  }
  return lease.heartbeatIntervalMs ? leaseTtlMs(env) : UNREFRESHED_LEASE_TTL_MS;
}

let processRunId: string | undefined;
let processStartedAt: number | undefined;

/** The run this process belongs to: `TEST_RUN_ID` when set, else generated once. */
export function currentRunId(env: NodeJS.ProcessEnv = process.env): string {
  const fromEnv = env.TEST_RUN_ID?.trim();
  if (fromEnv) {
    return fromEnv;
  }
  processRunId ??= `run-${randomUUID().slice(0, 8)}`;
  return processRunId;
}

/** Who is running: stamped into the state file and the lease. */
export function runIdentity(now = Date.now()): RunIdentity {
  processStartedAt ??= now;
  return {
    runId: currentRunId(),
    host: os.hostname(),
    checkout: process.cwd(),
    pid: process.pid,
    startedAt: processStartedAt,
    heartbeatAt: now,
  };
}

export interface Lease extends RunIdentity {
  testEnv?: string;
  /** Projects this run provisioned and still depends on. */
  usernames: string[];
  /** Set when a live process (the runner) refreshes the heartbeat on this cadence. */
  heartbeatIntervalMs?: number;
}

/** `TEST_LEASE_DIR` lets checkouts on one host share a directory; default is per checkout. */
export function leaseDir(env: NodeJS.ProcessEnv = process.env): string {
  const shared = env.TEST_LEASE_DIR?.trim();
  return shared ? path.resolve(shared) : path.join(PLAYWRIGHT_DIR, 'leases');
}

function leaseFile(runId: string, dir: string): string {
  return path.join(dir, `${runId.replace(/[^A-Za-z0-9._-]/g, '_')}.json`);
}

function readLeaseFile(file: string): Lease | undefined {
  try {
    const parsed = JSON.parse(fs.readFileSync(file, 'utf-8')) as Partial<Lease>;
    if (
      typeof parsed.runId !== 'string' ||
      typeof parsed.heartbeatAt !== 'number' ||
      !Array.isArray(parsed.usernames)
    ) {
      return undefined;
    }
    return parsed as Lease;
  } catch {
    return undefined;
  }
}

/**
 * Records this run's lease. Usernames already in the run's lease are kept, so the
 * user and DinD setups, which share a run id, do not erase each other's accounts.
 */
export function writeLease(
  usernames: readonly string[] = [],
  dir = leaseDir(),
  identity: RunIdentity = runIdentity()
): Lease {
  const existing = readLeaseFile(leaseFile(identity.runId, dir));
  const lease: Lease = {
    ...identity,
    testEnv: process.env.TEST_ENV?.trim() ? process.env.TEST_ENV.trim() : undefined,
    usernames: [...new Set([...(existing?.usernames ?? []), ...usernames])],
    ...(existing?.heartbeatIntervalMs !== undefined
      ? { heartbeatIntervalMs: existing.heartbeatIntervalMs }
      : {}),
  };
  fs.mkdirSync(dir, { recursive: true });
  fs.writeFileSync(leaseFile(identity.runId, dir), JSON.stringify(lease, null, 2), 'utf-8');
  return lease;
}

/** Bumps this run's heartbeat. Writes a fresh lease when none exists yet. */
export function refreshLease(
  dir = leaseDir(),
  now = Date.now(),
  runId = currentRunId(),
  heartbeatIntervalMs?: number
): Lease | undefined {
  const existing = readLeaseFile(leaseFile(runId, dir));
  if (!existing) {
    const created = writeLease([], dir, runIdentity(now));
    return heartbeatIntervalMs === undefined
      ? created
      : refreshLease(dir, now, runId, heartbeatIntervalMs);
  }
  const lease: Lease = {
    ...existing,
    heartbeatAt: now,
    ...(heartbeatIntervalMs !== undefined ? { heartbeatIntervalMs } : {}),
  };
  fs.writeFileSync(leaseFile(runId, dir), JSON.stringify(lease, null, 2), 'utf-8');
  return lease;
}

export function isLeaseActive(lease: Lease, now = Date.now(), ttlMs?: number): boolean {
  return now - lease.heartbeatAt < (ttlMs ?? leaseTtlFor(lease));
}

export interface LeaseHeartbeat {
  /** Stops the timer and stamps the lease one last time. */
  stop(): void;
}

export interface LeaseHeartbeatOptions {
  intervalMs?: number;
  /** Refreshes the lease; default stamps this run's lease file. */
  refresh?: (heartbeatIntervalMs: number) => unknown;
  setInterval?: (callback: () => void, ms: number) => { unref?: () => unknown };
  clearInterval?: (timer: { unref?: () => unknown }) => void;
}

/**
 * Keeps this run's lease fresh for as long as the calling process lives: one refresh
 * now, one every `intervalMs` (the timer is unref'd, so it never holds the process
 * open), and one more from `stop()`. A failing refresh is swallowed; the next tick
 * tries again.
 */
export function startLeaseHeartbeat(options: LeaseHeartbeatOptions = {}): LeaseHeartbeat {
  const intervalMs = options.intervalMs ?? LEASE_HEARTBEAT_INTERVAL_MS;
  const refresh =
    options.refresh ??
    ((interval: number) => refreshLease(leaseDir(), Date.now(), currentRunId(), interval));
  const schedule = options.setInterval ?? ((callback, ms) => setInterval(callback, ms));
  const unschedule = options.clearInterval ?? ((timer) => clearInterval(timer as NodeJS.Timeout));
  const beat = (): void => {
    try {
      refresh(intervalMs);
    } catch {
      // A missed beat is covered by the TTL's margin.
    }
  };
  beat();
  const timer = schedule(beat, intervalMs);
  timer.unref?.();
  return {
    stop(): void {
      unschedule(timer);
      beat();
    },
  };
}

/** Every readable lease in `dir`; unreadable files are ignored. Use `isLeaseActive` to filter. */
export function readLeases(dir = leaseDir()): Lease[] {
  let names: string[];
  try {
    names = fs.readdirSync(dir).filter((name) => name.endsWith('.json'));
  } catch {
    return [];
  }
  return names
    .map((name) => readLeaseFile(path.join(dir, name)))
    .filter((lease): lease is Lease => lease !== undefined);
}

/** One state file per TEST_ENV so webserver profiles never read each other's user. */
export function setupStatePath(testEnv = process.env.TEST_ENV?.trim()): string {
  const suffix = testEnv ? `-${testEnv}` : '';
  return path.join(STATE_DIR, `setup${suffix}.json`);
}

/** Every shared user in a state: the `users` list when there is one, else just `user`. */
export function setupStateUsers(state: Pick<SetupState, 'user' | 'users'>): SetupTestData[] {
  return state.users && state.users.length > 0 ? state.users : [state.user];
}

/** The user a worker runs against. A worker beyond the list wraps around rather than failing. */
export function pickSetupUser(
  state: Pick<SetupState, 'user' | 'users'>,
  parallelIndex: number | undefined
): SetupTestData {
  const users = setupStateUsers(state);
  const index =
    Number.isInteger(parallelIndex) && (parallelIndex ?? 0) >= 0 ? (parallelIndex ?? 0) : 0;
  return users[index % users.length];
}

/** Usernames of every shared user in the state file, for the leak check and leases. */
export function setupUsernames(state: Pick<SetupState, 'user' | 'users'> | undefined): string[] {
  return state ? setupStateUsers(state).map((user) => user.username) : [];
}

export function writeSetupState(state: SetupState): void {
  fs.mkdirSync(STATE_DIR, { recursive: true });
  fs.writeFileSync(setupStatePath(), JSON.stringify(state, null, 2), 'utf-8');
}

/** Returns the stored state, or `undefined` when setup has not run (or wrote garbage). */
export function readSetupState(): SetupState | undefined {
  const file = setupStatePath();
  if (!fs.existsSync(file)) {
    return undefined;
  }

  try {
    const parsed = JSON.parse(fs.readFileSync(file, 'utf-8')) as SetupState;
    return parsed.user?.username ? parsed : undefined;
  } catch {
    return undefined;
  }
}

/** Drops the state file so the next `setup` run provisions a fresh user. */
export function clearSetupState(): void {
  fs.rmSync(setupStatePath(), { force: true });
}

export function setupDindStatePath(testEnv = process.env.TEST_ENV?.trim()): string {
  const suffix = testEnv ? `-${testEnv}` : '';
  return path.join(STATE_DIR, `setup-dind${suffix}.json`);
}

export function writeSetupDindState(state: SetupDindState): void {
  fs.mkdirSync(STATE_DIR, { recursive: true });
  fs.writeFileSync(setupDindStatePath(), JSON.stringify(state, null, 2), 'utf-8');
}

export function readSetupDindState(): SetupDindState | undefined {
  const file = setupDindStatePath();
  if (!fs.existsSync(file)) {
    return undefined;
  }

  try {
    const parsed = JSON.parse(fs.readFileSync(file, 'utf-8')) as SetupDindState;
    return parsed.username ? parsed : undefined;
  } catch {
    return undefined;
  }
}

export function clearSetupDindState(): void {
  fs.rmSync(setupDindStatePath(), { force: true });
}
