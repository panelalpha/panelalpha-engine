import { appendFileSync, mkdirSync, readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from '@playwright/test';

import {
  currentRunId,
  isLeaseActive,
  readLeases,
  UNREFRESHED_LEASE_TTL_MS,
  type Lease,
} from '@/fixtures/helper/setup-state';
import { redactText } from './redact';

// Every project a test creates is written down before the create call and again at each
// step after it. The file is append-only, one JSON object per line, one file per run, so a
// crashed run still leaves the names of the projects it may have left on the Engine.

const suiteRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/** Where each run writes its resource events. */
export const RESOURCE_REGISTRY_DIR = path.join(suiteRoot, '.playwright', 'resources');

export type ResourceEventName =
  | 'creating'
  | 'created'
  | 'refused'
  | 'create-failed'
  | 'deleting'
  | 'deleted'
  | 'delete-failed'
  | 'preserved'
  | 'recovered';

export interface ResourceEvent {
  at: string;
  runId: string;
  /** Title path of the test that was running; absent outside a test. */
  test?: string;
  kind: 'project';
  /** The project username. */
  key: string;
  event: ResourceEventName;
  taskId?: number;
  detail?: string;
}

export interface ResourceRecord {
  key: string;
  event: ResourceEventName;
  taskId?: number;
  detail?: string;
}

const DETAIL_CHARS = 300;

/** The JSONL file of `runId` inside `dir`. */
export function resourceFile(
  runId: string = currentRunId(),
  dir: string = RESOURCE_REGISTRY_DIR
): string {
  return path.join(dir, `${runId.replace(/[^A-Za-z0-9._-]/g, '_')}.jsonl`);
}

function currentTestTitle(): string | undefined {
  try {
    const title = test.info().titlePath.filter((part) => part !== '');
    return title.length > 0 ? title.join(' > ') : undefined;
  } catch {
    return undefined;
  }
}

function shorten(detail: string): string {
  const redacted = redactText(detail).replace(/\s+/g, ' ').trim();
  return redacted.length <= DETAIL_CHARS ? redacted : `${redacted.slice(0, DETAIL_CHARS)}…`;
}

/** Appends one event for this run; never throws. */
export function recordResource(
  record: ResourceRecord,
  options: { runId?: string; dir?: string; testTitle?: string } = {}
): void {
  try {
    const runId = options.runId ?? currentRunId();
    const testTitle = options.testTitle ?? currentTestTitle();
    const event: ResourceEvent = {
      at: new Date().toISOString(),
      runId,
      ...(testTitle !== undefined ? { test: testTitle } : {}),
      kind: 'project',
      key: record.key,
      event: record.event,
      ...(record.taskId !== undefined ? { taskId: record.taskId } : {}),
      ...(record.detail !== undefined && record.detail !== ''
        ? { detail: shorten(record.detail) }
        : {}),
    };
    const dir = options.dir ?? RESOURCE_REGISTRY_DIR;
    mkdirSync(dir, { recursive: true });
    appendFileSync(resourceFile(runId, dir), `${JSON.stringify(event)}\n`, { mode: 0o600 });
  } catch {
    // Bookkeeping must not fail a test or hide its error.
  }
}

/** Events in `file`, oldest first; unreadable lines are skipped and a missing file is empty. */
export function readResourceEvents(file: string): ResourceEvent[] {
  let text: string;
  try {
    text = readFileSync(file, 'utf8');
  } catch {
    return [];
  }
  const events: ResourceEvent[] = [];
  for (const line of text.split('\n')) {
    if (line.trim() === '') {
      continue;
    }
    try {
      const parsed = JSON.parse(line) as Partial<ResourceEvent>;
      if (typeof parsed.key === 'string' && typeof parsed.event === 'string') {
        events.push(parsed as ResourceEvent);
      }
    } catch {
      // A torn last line from a killed process.
    }
  }
  return events;
}

/** Keys whose last event is none of `deleted`, `refused`, `recovered`: possibly still on the Engine. */
export function openResources(events: readonly ResourceEvent[]): string[] {
  const last = new Map<string, ResourceEventName>();
  for (const event of events) {
    last.set(event.key, event.event);
  }
  return [...last.entries()]
    .filter(([, event]) => event !== 'deleted' && event !== 'refused' && event !== 'recovered')
    .map(([key]) => key);
}

export interface PreservePolicy {
  /** A failed test keeps its projects. */
  keep: boolean;
  /** PRESERVE_ON_FAILURE=1 was set in CI and is ignored. */
  ignoredInCi: boolean;
}

/** Keeping a project after a failure is a local opt-in; CI always deletes. */
export function preservePolicy(env: NodeJS.ProcessEnv = process.env): PreservePolicy {
  const requested = env.PRESERVE_ON_FAILURE?.trim() === '1';
  const ci = env.CI !== undefined && env.CI.trim() !== '' && env.CI.trim() !== '0';
  return { keep: requested && !ci, ignoredInCi: requested && ci };
}

let warnedCi = false;

/** The policy, printing one warning per process when CI overrides PRESERVE_ON_FAILURE=1. */
export function resolvePreservePolicy(env: NodeJS.ProcessEnv = process.env): PreservePolicy {
  const policy = preservePolicy(env);
  if (policy.ignoredInCi && !warnedCi) {
    warnedCi = true;
    console.warn(
      'PRESERVE_ON_FAILURE=1 is ignored because CI is set; failed projects are deleted.'
    );
  }
  return policy;
}

export interface RecoveryEngine {
  /** False when the Engine answers 404 for the project (already gone). */
  exists(username: string): Promise<boolean>;
  /** Deletes the project; a 404 is not an error. */
  remove(username: string): Promise<void>;
}

export interface RecoverOrphanedResourcesOptions {
  /** The run doing the recovery; its own file is never touched. */
  currentRunId?: string;
  dir?: string;
  leases?: readonly Lease[];
  /** Projects of this run's setup, never deleted. */
  keepUsernames?: readonly string[];
  now?: number;
  /**
   * A run without any lease is only recovered once its file has been idle this long, so a
   * run that never wrote a lease cannot lose its projects while it is still going.
   */
  idleMs?: number;
}

export interface RecoveredResource {
  username: string;
  runId: string;
  /** `deleted` when the project was removed now, `gone` when the Engine no longer had it. */
  outcome: 'deleted' | 'gone';
}

export interface KeptResource {
  username: string;
  runId: string;
  reason: string;
}

export interface RecoverOrphanedResourcesResult {
  recovered: RecoveredResource[];
  kept: KeptResource[];
}

/**
 * Deletes the projects an earlier run of this checkout left open (see `openResources`) when
 * that run's lease is no longer active, then appends a `recovered` event to the run's file.
 * Kept: anything of a run with an active lease, the current run, the current setup users,
 * a project preserved on purpose, and anything whose delete failed (it stays open).
 */
export async function recoverOrphanedResources(
  engine: RecoveryEngine,
  options: RecoverOrphanedResourcesOptions = {}
): Promise<RecoverOrphanedResourcesResult> {
  const dir = options.dir ?? RESOURCE_REGISTRY_DIR;
  const now = options.now ?? Date.now();
  const self = options.currentRunId ?? currentRunId();
  const idleMs = options.idleMs ?? UNREFRESHED_LEASE_TTL_MS;
  const keep = new Set((options.keepUsernames ?? []).map((name) => name.trim().toLowerCase()));
  const leases = options.leases ?? readLeases();
  const leasedRuns = new Map(leases.map((lease) => [lease.runId, lease]));
  const result: RecoverOrphanedResourcesResult = { recovered: [], kept: [] };
  const protectedNames = new Set<string>();
  for (const lease of leases) {
    if (lease.runId !== self && isLeaseActive(lease, now)) {
      for (const name of lease.usernames) {
        protectedNames.add(name.trim().toLowerCase());
      }
    }
  }

  let files: string[];
  try {
    files = readdirSync(dir).filter((name) => name.endsWith('.jsonl'));
  } catch {
    return result;
  }

  for (const name of files.sort()) {
    const file = path.join(dir, name);
    const events = readResourceEvents(file);
    const runId = events.find((event) => typeof event.runId === 'string')?.runId;
    if (runId === undefined || runId === self || resourceFile(runId, dir) !== file) {
      continue;
    }
    const open = openResources(events);
    if (open.length === 0) {
      continue;
    }
    const lease = leasedRuns.get(runId);
    let blocked: string | undefined;
    if (lease && isLeaseActive(lease, now)) {
      blocked = 'its run lease is still active';
    } else if (!lease) {
      try {
        if (now - statSync(file).mtimeMs < idleMs) {
          blocked = 'its run has no lease but wrote to the registry recently';
        }
      } catch {
        blocked = 'its registry file cannot be read';
      }
    }
    for (const username of open) {
      const last = [...events].reverse().find((event) => event.key === username)?.event;
      const reason =
        blocked ??
        (keep.has(username.toLowerCase())
          ? 'current setup user'
          : protectedNames.has(username.toLowerCase())
            ? 'owned by an active run lease'
            : last === 'preserved'
              ? 'preserved on purpose'
              : undefined);
      if (reason !== undefined) {
        result.kept.push({ username, runId, reason });
        continue;
      }
      try {
        const outcome = (await engine.exists(username)) ? 'deleted' : 'gone';
        if (outcome === 'deleted') {
          await engine.remove(username);
        }
        result.recovered.push({ username, runId, outcome });
        recordResource(
          { key: username, event: 'recovered', detail: outcome },
          { runId, dir, testTitle: 'recovery' }
        );
      } catch (error) {
        result.kept.push({
          username,
          runId,
          reason: `recovery failed: ${error instanceof Error ? error.message : String(error)}`,
        });
      }
    }
  }
  return result;
}
