import { spawn } from 'node:child_process';
import dotenv from 'dotenv';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

import {
  describeParallelism,
  phaseEnvironment,
  resolveParallelism,
  type Parallelism,
  type Phase,
} from '@/config/parallelism';
import { currentRunId, startLeaseHeartbeat } from '@/fixtures/helper/setup-state';
import { insecureFetch } from '@/helpers/insecure-fetch';
import { ensureEngineEnv, suiteRoot } from './lib/engine-env';
import { beginLeakCheck, finishLeakCheck, leakCheckEnabled } from './lib/leak-check/run';
import {
  combinedExitCode,
  countJunitSkipped,
  fetchEngineRamBytes,
  hasProjectArg,
  phaseArgs,
  phaseJunitFile,
  skipBudgetFailure,
  writeParallelismFile,
} from './lib/phases';
import { reportListenUrl, resolvePublicReportHost } from './lib/report-host';
import {
  describeSelection,
  planPrRun,
  writeSelectionFile,
  type PrSelection,
} from './lib/pr-selection';
import { preparePlaywright } from './lib/playwright-cli';
import {
  SUPPORTED_APPS_NOTICE,
  describeNightly,
  fullScope,
  nightlyPlan,
  playwrightArgs,
  shouldPrintSupportedAppsNotice,
} from './lib/run-levels';

/**
 * The suite's front door: `bun run test`.
 *
 * Makes sure there is an engine to point at — creating `env/.env` on the spot
 * when there is not — and then hands over to Playwright. Every argument after
 * `--` goes straight through, so `bun run test -- tests/users --headed` behaves the
 * way `playwright test` would.
 *
 * `webserver-change`, `update`, `cli`, `engine-cert`, `network-mutation` and
 * `slow` are left out of the default run: they either reconfigure the engine,
 * need host SSH for `pae-artisan`, or turn the host firewall off and on, or walk
 * the full PHP-version matrix. The `supported-apps` catalogue runs only with `--full`.
 *
 * With `LEAK_CHECK=1` (the default when `CI` is set; `LEAK_CHECK=0` turns it off)
 * the engine is inventoried before and after the run, and the exit code is
 * non-zero when the run left something behind. See `bun run leak-check`.
 *
 * With `TEST_PARALLEL=auto` (or a number) the run is sized to the Engine host and, when that
 * means more than one worker, split in two phases: `unit`, `setup`, `api-parallel` (and
 * `supported-apps` with `--full`) first, then `api-serial` alone on the Engine. Each phase has its own output
 * directory, HTML report and JUnit file (suffix `-parallel` / `-serial`); both always run; the
 * leak-check and the `MAX_SKIPPED` budget cover the two together. Without `TEST_PARALLEL`
 * nothing changes: one Playwright invocation, one worker.
 *
 * Run levels (see README):
 *   (default)              unit + api (setup comes in as a dependency); prints a line that the
 *                          supported apps were not run.
 *   --full / test:full     the default plus supported-apps.
 *   --pr                   the selection from scripts/lib/pr-selection.ts: `@smoke`, the specs the
 *                          changed paths map to, and a few supported apps. Typecheck, lint and
 *                          the unit project are separate steps of `bun run test:pr`.
 *   --pr --plan            print and write the selection (.playwright/pr-selection.json), run nothing.
 *   --nightly              the full scope (--full), then `slow` and the engine-wide projects whose opt-in is
 *                          set (scripts/lib/run-levels.ts).
 */

function printReportHint(split: boolean): void {
  const envPort = process.env.REPORT_PORT?.trim();
  const port = envPort !== undefined && envPort.length > 0 ? envPort : '9323';
  const url = reportListenUrl(resolvePublicReportHost(suiteRoot), port);
  console.log(`\nHTML report: bun run report`);
  console.log(`  then open ${url}`);
  console.log(`  (or tunnel: ssh -L ${port}:127.0.0.1:${port} <user>@${new URL(url).hostname})\n`);
  if (split) {
    const profile = process.env.TEST_ENV?.trim();
    const suffix = profile ? `-${profile}` : '';
    console.log('  A split run keeps one report per phase:');
    for (const phase of PHASES) {
      console.log(`    bunx playwright show-report .playwright/report${suffix}-${phase}`);
    }
    console.log('');
  }
}

const PHASES: Phase[] = ['parallel', 'serial'];

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

/** False when `TEST_PARALLEL` is unset or says off: nothing is fetched and nothing is printed. */
function parallelRequested(): boolean {
  const raw = process.env.TEST_PARALLEL?.trim().toLowerCase() ?? '';
  return !['', '0', 'off', 'false'].includes(raw);
}

/** Worker counts for this run. Does not touch the network unless `TEST_PARALLEL` is on. */
async function decideParallelism(): Promise<Parallelism> {
  if (parallelRequested() === false) {
    return resolveParallelism({ env: process.env, cpus: os.availableParallelism() });
  }

  loadEnvFiles();
  const baseUrl = process.env.API_BASE_URL?.trim();
  const token = process.env.API_TOKEN?.trim();
  const engineRamBytes =
    baseUrl && token
      ? await fetchEngineRamBytes(baseUrl, token, { fetch: insecureFetch })
      : undefined;
  return resolveParallelism({
    env: process.env,
    engineRamBytes,
    cpus: os.availableParallelism(),
  });
}

let playwright: ReturnType<typeof preparePlaywright> | undefined;

function runPlaywright(args: string[], env: NodeJS.ProcessEnv): Promise<number> {
  const launch = (playwright ??= preparePlaywright());
  return new Promise((resolve) => {
    const child = spawn(launch.command, [...launch.args, ...args], {
      stdio: 'inherit',
      env,
    });
    child.on('error', () => resolve(1));
    child.on('exit', (code, signal) => resolve(signal ? 1 : (code ?? 1)));
  });
}

function readFileIfAny(file: string): string | undefined {
  try {
    return fs.readFileSync(file, 'utf-8');
  } catch {
    return undefined;
  }
}

/** `.playwright/summary.md` for a split run: the choice, then each phase's own summary. */
function writeCombinedSummary(parallelism: Parallelism, phaseCodes: Record<string, number>): void {
  const dir = path.join(suiteRoot, '.playwright');
  const lines = [
    '## Parallelism',
    '',
    describeParallelism(parallelism),
    ...Object.entries(phaseCodes).map(([phase, code]) => `- ${phase}: exit code ${code}`),
    '',
  ];
  for (const phase of PHASES) {
    const text = readFileIfAny(path.join(dir, `summary-${phase}`, 'summary.md'));
    if (text) {
      lines.push(`### Phase ${phase}`, '', text.trim(), '');
    }
  }
  try {
    fs.writeFileSync(path.join(dir, 'summary.md'), `${lines.join('\n')}\n`);
  } catch {
    // The phase summaries are still on disk.
  }
}

async function runSplit(
  parallelism: Parallelism,
  passthrough: string[],
  full: boolean
): Promise<{
  code: number;
  skipFailure: string | undefined;
}> {
  const codes: Record<string, number> = {};
  let skipped = 0;
  for (const phase of PHASES) {
    const junit = phaseJunitFile(phase, process.env.JUNIT_REPORT_FILE, suiteRoot);
    fs.rmSync(junit, { force: true });
    console.log(
      `\n=== Phase ${phase}: ${phaseArgs({ phase, parallelism, passthrough, full }).slice(1).join(' ')}\n`
    );
    codes[phase] = await runPlaywright(phaseArgs({ phase, parallelism, passthrough, full }), {
      ...process.env,
      ...phaseEnvironment(parallelism, phase),
      JUNIT_REPORT_FILE: junit,
    });
    skipped += countJunitSkipped(junit);
    console.log(`\n=== Phase ${phase} finished with exit code ${codes[phase]}`);
  }

  const skipFailure = skipBudgetFailure(skipped, process.env);
  console.log(`\nSkipped across both phases: ${skipped}`);
  if (skipFailure) {
    console.log(`\n  ${skipFailure}`);
  }
  writeCombinedSummary(parallelism, codes);
  writeParallelismFile(suiteRoot, {
    ...parallelism,
    split: true,
    phases: codes,
    skipped,
    finishedAt: new Date().toISOString(),
  });
  return {
    code: combinedExitCode(Object.values(codes), skipFailure !== undefined, true),
    skipFailure,
  };
}

const LEVEL_FLAGS = ['--pr', '--plan', '--nightly', '--full'];
const rawArgs = process.argv.slice(2);
const prMode = rawArgs.includes('--pr');
const planOnly = rawArgs.includes('--plan');
const nightlyMode = rawArgs.includes('--nightly');
const full = fullScope({ full: rawArgs.includes('--full'), nightly: nightlyMode, pr: prMode });
let passthrough = rawArgs.filter((arg) => !LEVEL_FLAGS.includes(arg));

let prSelection: PrSelection | undefined;
if (prMode) {
  prSelection = planPrRun(suiteRoot, process.env);
  console.log(`\n${describeSelection(prSelection)}`);
  console.log(`  written to ${writeSelectionFile(suiteRoot, prSelection)}\n`);
  if (planOnly) {
    process.exit(0);
  }
  if (prSelection.apps.length > 0 && !process.env.SUPPORTED_APPS?.trim()) {
    process.env.SUPPORTED_APPS = prSelection.apps.join(',');
  }
  passthrough = [...prSelection.filters, '--pass-with-no-tests', ...passthrough];
} else if (planOnly) {
  console.error('--plan only goes with --pr.');
  process.exit(2);
}

await ensureEngineEnv(process.env.TEST_ENV?.trim()).catch((error: unknown) => {
  console.error(`\n${error instanceof Error ? error.message : String(error)}`);
  process.exit(1);
});

const parallelism = await decideParallelism();
const split = parallelism.apiWorkers > 1 && !hasProjectArg(passthrough);
if (parallelRequested()) {
  console.log(`\n${describeParallelism(parallelism)}`);
  if (parallelism.apiWorkers > 1 && !split) {
    console.log('  --project was given, so the run is not split: one Playwright invocation.');
  } else if (!split) {
    console.log('  One worker: the run is the same as without TEST_PARALLEL.');
  }
  writeParallelismFile(suiteRoot, { ...parallelism, split });
}

// One run id for the runner and every Playwright child, so the setup's lease is the one the
// heartbeat refreshes. The runner lives for the whole run, both parallel phases included.
process.env.TEST_RUN_ID = currentRunId();
const heartbeat = startLeaseHeartbeat();

const leakCheck = leakCheckEnabled() ? await beginLeakCheck() : undefined;
if (leakCheck?.error) {
  console.error(`\nLeak-check: ${leakCheck.error}. Tests will run, but the check cannot complete.`);
}

let playwrightCode: number;
if (split) {
  const outcome = await runSplit(parallelism, passthrough, full);
  playwrightCode = outcome.code;
} else {
  playwrightCode = await runPlaywright(playwrightArgs(passthrough, full), process.env);
}

if (nightlyMode) {
  const plan = nightlyPlan(process.env);
  console.log(`\n${describeNightly(plan)}\n`);
  for (const entry of plan.filter((item) => item.run)) {
    console.log(`\n=== Nightly project ${entry.project}\n`);
    const code = await runPlaywright(['test', `--project=${entry.project}`, '--workers=1'], {
      ...process.env,
    });
    console.log(`\n=== Nightly project ${entry.project} finished with exit code ${code}`);
    if (playwrightCode === 0) {
      playwrightCode = code;
    }
  }
}

heartbeat.stop();
const clean = leakCheck ? await finishLeakCheck(leakCheck) : true;
printReportHint(split);
if (shouldPrintSupportedAppsNotice(full, passthrough)) {
  console.log(SUPPORTED_APPS_NOTICE);
}
if (leakCheck) {
  console.log(`Playwright exit code ${playwrightCode}; leak-check ${clean ? 'clean' : 'FAILED'}.`);
}
process.exit(playwrightCode !== 0 ? playwrightCode : clean ? 0 : 1);
