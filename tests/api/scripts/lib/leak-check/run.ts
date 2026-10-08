import dotenv from 'dotenv';
import fs from 'node:fs';
import path from 'node:path';
import { request } from '@playwright/test';

import { EngineApi } from '@/clients/engine-api';
import { createApiTransport } from '@/clients/api-transport';
import { readSetupDindState, readSetupState, setupUsernames } from '@/fixtures/helper/setup-state';
import { describeError, takeSnapshot } from './snapshot';
import { buildAllowList } from './allow';
import { compareSnapshots } from './compare';
import { suiteRoot } from '../engine-env';
import type { CompareResult, LeakCheckResult, Snapshot } from './types';

const LISTED_LIMIT = 20;
const REQUEST_TIMEOUT_MS = 60_000;

export const leakCheckDir = path.join(suiteRoot, '.playwright', 'leak-check');

/** `LEAK_CHECK=1` turns it on, `LEAK_CHECK=0` off; unset follows `CI`. */
export function leakCheckEnabled(env: NodeJS.ProcessEnv = process.env): boolean {
  const flag = env.LEAK_CHECK?.trim();
  if (flag === '1' || flag === 'true') {
    return true;
  }
  if (flag === '0' || flag === 'false') {
    return false;
  }
  const ci = env.CI?.trim();
  return ci !== undefined && ci !== '' && ci !== '0' && ci !== 'false';
}

/** Same lookup the Playwright config does: `env/.env`, then `env/.env.<TEST_ENV>` on top. */
function loadEnvFiles(): void {
  dotenv.config({ path: path.join(suiteRoot, 'env/.env'), quiet: true });
  const profile = process.env.TEST_ENV?.trim();
  if (profile) {
    dotenv.config({
      path: path.join(suiteRoot, `env/.env.${profile}`),
      override: true,
      quiet: true,
    });
  }
}

export function writeJson(file: string, value: unknown): void {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`, 'utf8');
}

export function readSnapshot(file: string): Snapshot {
  const parsed = JSON.parse(fs.readFileSync(file, 'utf8')) as Snapshot;
  if (parsed.version !== 1 || typeof parsed.sections !== 'object' || parsed.sections === null) {
    throw new Error(`${file} is not a leak-check snapshot`);
  }
  return parsed;
}

/** Snapshot of the engine named by `API_BASE_URL` / `API_TOKEN` (env files included). */
export async function snapshotEngine(): Promise<Snapshot> {
  loadEnvFiles();
  const baseURL = process.env.API_BASE_URL?.trim();
  if (!baseURL) {
    throw new Error('API_BASE_URL is not set; cannot reach the engine for a leak-check snapshot.');
  }
  const token = process.env.API_TOKEN?.trim();
  const context = await request.newContext({
    baseURL: baseURL.endsWith('/') ? baseURL : `${baseURL}/`,
    ignoreHTTPSErrors: true,
    timeout: REQUEST_TIMEOUT_MS,
    extraHTTPHeaders: {
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });
  try {
    return await takeSnapshot(new EngineApi(createApiTransport(context)));
  } finally {
    await context.dispose();
  }
}

/** Projects the setup projects left on purpose, read from the state files. */
export function setupUsers(): string[] {
  const users: string[] = [];
  try {
    // A parallel run keeps one shared user per worker.
    users.push(...setupUsernames(readSetupState()));
  } catch {
    // A state file that cannot be read means that project is not exempt.
  }
  try {
    const dind = readSetupDindState();
    if (dind?.username) {
      users.push(dind.username);
    }
  } catch {
    // Same as above.
  }
  return users;
}

export function compareWithSetupAllow(before: Snapshot, after: Snapshot): CompareResult {
  return compareSnapshots(before, after, { allow: buildAllowList(setupUsers()) });
}

function line(kind: string, section: string, key: string, detail: string): string {
  return `  ${kind} ${section}/${key}${detail ? ` ${detail}` : ''}`;
}

/** A short, human-readable account of a comparison. */
export function formatSummary(result: CompareResult): string {
  const lines = [
    `Leak-check: ${result.ok ? 'clean' : 'FAILED'} — ${result.leaks.length} leaked, ` +
      `${result.changed.length} changed, ${result.errors.length} errors, ` +
      `${result.allowed.length} allowed, ${result.removed.length} removed`,
  ];
  const detail: string[] = [
    ...result.leaks.map((l) => line('leak   ', l.section, l.key, l.detail)),
    ...result.changed.map((c) =>
      line('changed', c.section, c.key, `${JSON.stringify(c.before)} -> ${JSON.stringify(c.after)}`)
    ),
    ...result.errors.map((e) => line('error  ', e.section, e.side, e.error)),
  ];
  lines.push(...detail.slice(0, LISTED_LIMIT));
  if (detail.length > LISTED_LIMIT) {
    lines.push(
      `  … and ${detail.length - LISTED_LIMIT} more (see ${path.join(leakCheckDir, 'result.json')})`
    );
  }
  return lines.join('\n');
}

export interface LeakCheckHandle {
  startedAt: string;
  before?: Snapshot;
  error?: string;
}

/** Takes the "before" snapshot. Never throws: a failure is kept on the handle. */
export async function beginLeakCheck(): Promise<LeakCheckHandle> {
  const handle: LeakCheckHandle = { startedAt: new Date().toISOString() };
  // A result from an earlier run must not be mistaken for this one.
  for (const name of ['result.json', 'after.json']) {
    fs.rmSync(path.join(leakCheckDir, name), { force: true });
  }
  try {
    handle.before = await snapshotEngine();
    writeJson(path.join(leakCheckDir, 'before.json'), handle.before);
  } catch (error) {
    handle.error = `before snapshot failed: ${describeError(error)}`;
  }
  return handle;
}

/**
 * Takes the "after" snapshot, compares, writes `after.json` and `result.json`
 * and prints the summary. Returns whether the engine is clean; never throws.
 */
export async function finishLeakCheck(handle: LeakCheckHandle): Promise<boolean> {
  let result: CompareResult;
  try {
    if (!handle.before) {
      throw new Error(handle.error ?? 'no before snapshot');
    }
    const after = await snapshotEngine();
    writeJson(path.join(leakCheckDir, 'after.json'), after);
    result = compareWithSetupAllow(handle.before, after);
  } catch (error) {
    result = {
      ok: false,
      leaks: [],
      changed: [],
      errors: [{ section: 'leak-check', side: 'run', error: describeError(error) }],
      allowed: [],
      removed: [],
    };
  }

  const full: LeakCheckResult = {
    ...result,
    startedAt: handle.startedAt,
    finishedAt: new Date().toISOString(),
  };
  try {
    writeJson(path.join(leakCheckDir, 'result.json'), full);
  } catch (error) {
    console.error(`Leak-check: could not write result.json (${describeError(error)})`);
  }
  console.log(`\n${formatSummary(full)}\n`);
  return full.ok;
}
