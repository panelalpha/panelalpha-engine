import fs from 'fs';
import path from 'path';
import {
  isLeaseActive,
  readLeases,
  UNREFRESHED_LEASE_TTL_MS,
  type Lease,
} from '@/fixtures/helper/setup-state';
import type { EngineApi } from '@/clients/engine-api';
import { isTestUsername } from '@/helpers/random';
import type { UserFactory } from '@/test-data/factories/user.factory';
import type { SetupTestData, SystemChangeStatus } from '@/types';

export interface WebserverChangeDebugContext {
  setupUsername?: string;
  setupDomain?: string;
  setupUrl?: string;
  stuckUsername?: string;
  logsPath?: string;
}

export interface CleanupStaleTestUsersOptions {
  /** The current setup user, and any other account this run still needs. */
  keepUsernames?: string[];
  /** Leases to honour; default is every lease in the lease directory. */
  leases?: readonly Lease[];
  now?: number;
  /**
   * A project must be older than this to be removed; default is three hours. Not the
   * lease TTL: a live run's own projects are not in its lease, so only their age protects them.
   */
  ttlMs?: number;
}

export interface KeptTestUser {
  username: string;
  reason: string;
}

export interface CleanupStaleTestUsersResult {
  removed: string[];
  kept: KeptTestUser[];
  /** True when the project list could not be read; nothing was touched. */
  listFailed: boolean;
}

/** Whether a GET /projects/{name} body is the record of `username`. */
export function recordMatchesUsername(body: unknown, username: string): boolean {
  const data = (body as { data?: { username?: unknown } } | null | undefined)?.data;
  return (
    typeof data?.username === 'string' &&
    data.username.trim().toLowerCase() === username.trim().toLowerCase()
  );
}

export function parseStuckUsernameFromChangeTail(
  change: SystemChangeStatus | null | undefined
): string | undefined {
  const haystack = `${change?.tail_stdout ?? ''}\n${change?.tail_stderr ?? ''}`;
  const match = /\/(?:projects|users)\/([a-z0-9_-]+)\//i.exec(haystack);
  return match?.[1];
}

export function buildWebserverChangeDebugContext(
  setupData: SetupTestData | undefined,
  change: SystemChangeStatus | null | undefined
): WebserverChangeDebugContext {
  return {
    setupUsername: setupData?.username,
    setupDomain: setupData?.domain,
    setupUrl: setupData?.url,
    stuckUsername: parseStuckUsernameFromChangeTail(change),
    logsPath: change?.logs_path ?? '/opt/panelalpha/log/change-webserver/latest',
  };
}

export function formatWebserverChangeDebugMessage(debug: WebserverChangeDebugContext): string {
  const lines = ['Debug — setup user (left on server):'];
  if (debug.setupUsername) {
    lines.push(`  username: ${debug.setupUsername}`);
  }
  if (debug.setupDomain) {
    lines.push(`  domain:   ${debug.setupDomain}`);
  }
  if (debug.setupUrl) {
    lines.push(`  url:      ${debug.setupUrl}`);
  }
  if (debug.logsPath) {
    lines.push(`Change log: ${debug.logsPath}`);
  }
  if (debug.stuckUsername) {
    lines.push(`Stuck on user (from rebuild tail): ${debug.stuckUsername}`);
  }
  return lines.join('\n');
}

export function writeSetupDebugEnv(debug: WebserverChangeDebugContext): void {
  const dir = path.resolve(process.cwd(), '.playwright');
  fs.mkdirSync(dir, { recursive: true });
  const lines = [
    `SETUP_USERNAME=${debug.setupUsername ?? ''}`,
    `SETUP_DOMAIN=${debug.setupDomain ?? ''}`,
    `SETUP_URL=${debug.setupUrl ?? ''}`,
    `STUCK_USERNAME=${debug.stuckUsername ?? ''}`,
    `CHANGE_LOG_PATH=${debug.logsPath ?? ''}`,
  ];
  fs.writeFileSync(path.join(dir, 'setup-debug.env'), `${lines.join('\n')}\n`, 'utf-8');
}

/**
 * Persist the full latest_webserver_change snapshot to .playwright/change-snapshot.json.
 * Survives engine reinstalls (which wipe /opt/panelalpha/log/change-webserver/*) so the
 * change stdout/stderr tail remains available as an orchestrator run artifact.
 */
export function writeChangeSnapshot(change: SystemChangeStatus | null | undefined): void {
  const dir = path.resolve(process.cwd(), '.playwright');
  fs.mkdirSync(dir, { recursive: true });
  fs.writeFileSync(
    path.join(dir, 'change-snapshot.json'),
    JSON.stringify(change ?? {}, null, 2),
    'utf-8'
  );
}

/**
 * Removes `pw*` projects that no live run can still be using. A project is deleted
 * only when it is not in `keepUsernames`, no active lease lists it, and the
 * Engine's own `created_at` (UserResource.php:46, served by GET /projects/all)
 * puts it beyond the lease TTL. A project whose age cannot be read is kept.
 */
export async function cleanupStaleTestUsers(
  api: EngineApi,
  userFactory: UserFactory,
  options: CleanupStaleTestUsersOptions = {}
): Promise<CleanupStaleTestUsersResult> {
  const now = options.now ?? Date.now();
  const ttlMs = options.ttlMs ?? UNREFRESHED_LEASE_TTL_MS;
  const keep = new Set(
    (options.keepUsernames ?? []).map((username) => username.trim().toLowerCase()).filter(Boolean)
  );
  const leased = new Set<string>();
  for (const lease of options.leases ?? readLeases()) {
    if (isLeaseActive(lease, now, options.ttlMs)) {
      for (const username of lease.usernames) {
        leased.add(username.trim().toLowerCase());
      }
    }
  }
  const result: CleanupStaleTestUsersResult = { removed: [], kept: [], listFailed: false };

  let users: { username: string; domain: string; createdAt?: string }[] = [];
  try {
    const response = await api.listAllUsers(true);
    users = (response.data ?? []).map((user) => ({
      username: user.username,
      domain: user.domain,
      createdAt: user.created_at,
    }));
  } catch (error) {
    console.warn(
      '[cleanup] Failed to list users for stale cleanup:',
      error instanceof Error ? error.message : error
    );
    result.listFailed = true;
    return result;
  }

  const keepUser = (username: string, reason: string): void => {
    result.kept.push({ username, reason });
    console.log(`[cleanup] Kept ${username}: ${reason}`);
  };

  for (const user of users) {
    const username = user.username.trim();
    if (!username || !isTestUsername(username)) {
      continue;
    }
    const key = username.toLowerCase();
    if (keep.has(key)) {
      keepUser(username, 'current setup user');
      continue;
    }
    if (leased.has(key)) {
      keepUser(username, 'owned by an active run lease');
      continue;
    }
    const createdAt = user.createdAt ? Date.parse(user.createdAt) : Number.NaN;
    if (Number.isNaN(createdAt)) {
      keepUser(username, 'age unknown (no usable created_at from the Engine)');
      continue;
    }
    const ageMs = now - createdAt;
    if (ageMs < ttlMs) {
      keepUser(
        username,
        `younger than the minimum age (${Math.round(ageMs / 60_000)} of ${Math.round(ttlMs / 60_000)} min)`
      );
      continue;
    }
    try {
      await userFactory.deleteUser(username);
      result.removed.push(username);
      console.log(`[cleanup] Removed stale test user: ${username} (${user.domain})`);
    } catch (error) {
      keepUser(
        username,
        `delete failed: ${error instanceof Error ? error.message : String(error)}`
      );
    }
  }

  return result;
}
