import fs from 'node:fs';
import path from 'node:path';

import type { Parallelism, Phase } from '@/config/parallelism';

/**
 * Pieces of the split run in `scripts/run-tests.ts`: the Engine's RAM, the argument list of each
 * Playwright phase, the per-phase JUnit file and the skip budget judged over both phases.
 */

const METRICS_TIMEOUT_MS = 10_000;
const KIB = 1024;

export interface MetricsDeps {
  fetch: (
    input: string,
    init?: { headers?: Record<string, string>; signal?: AbortSignal }
  ) => Promise<Response>;
  timeoutMs?: number;
}

/**
 * `ram_total` of GET metrics/current, in bytes. The Engine reports it in kB, straight from
 * `MemTotal` in /proc/meminfo (ServerMetricsController::current). Anything that goes wrong
 * is "unknown": the caller falls back to one worker, and the token never reaches a message.
 */
export async function fetchEngineRamBytes(
  apiBaseUrl: string,
  token: string,
  deps: MetricsDeps
): Promise<number | undefined> {
  try {
    const base = apiBaseUrl.endsWith('/') ? apiBaseUrl : `${apiBaseUrl}/`;
    const response = await deps.fetch(`${base}metrics/current`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      signal: AbortSignal.timeout(deps.timeoutMs ?? METRICS_TIMEOUT_MS),
    });
    if (!response.ok) {
      return undefined;
    }
    return ramBytesFromMetrics(await response.json());
  } catch {
    return undefined;
  }
}

export function ramBytesFromMetrics(body: unknown): number | undefined {
  const data = (body as { data?: { ram_total?: unknown } } | null)?.data;
  const kb = Number(data?.ram_total);
  return Number.isFinite(kb) && kb > 0 ? kb * KIB : undefined;
}

export interface PhaseArgsInput {
  phase: Phase;
  parallelism: Pick<Parallelism, 'apiWorkers'>;
  passthrough: string[];
  /** Full scope adds `supported-apps` to the first phase. Default: false. */
  full?: boolean;
}

/** Projects of the first phase: the ones that may share the Engine with several workers. */
export const PARALLEL_PHASE_PROJECTS = ['unit', 'setup', 'api-parallel'];
/** Added to the first phase for the full scope only. */
export const FULL_PHASE_EXTRA_PROJECTS = ['supported-apps'];
/** Projects of the second phase: alone on the Engine, reusing the setup users of the first. */
export const SERIAL_PHASE_PROJECTS = ['api-serial'];

/**
 * Arguments of one Playwright invocation. The serial phase never runs `setup` again (it has no
 * dependencies, and `--no-deps` says so) and always uses one worker, whatever is passed through.
 * `--pass-with-no-tests` keeps a file or `--grep` filter that matches only one phase from failing
 * the other.
 */
export function phaseArgs(input: PhaseArgsInput): string[] {
  if (input.phase === 'parallel') {
    return [
      'test',
      ...[...PARALLEL_PHASE_PROJECTS, ...(input.full ? FULL_PHASE_EXTRA_PROJECTS : [])].map(
        (project) => `--project=${project}`
      ),
      `--workers=${input.parallelism.apiWorkers}`,
      '--pass-with-no-tests',
      ...input.passthrough,
    ];
  }
  return [
    'test',
    ...SERIAL_PHASE_PROJECTS.map((project) => `--project=${project}`),
    '--no-deps',
    '--pass-with-no-tests',
    ...input.passthrough,
    '--workers=1',
  ];
}

/** `report.xml` becomes `report-parallel.xml`; without a base the file lives in `.playwright`. */
export function phaseJunitFile(phase: Phase, base: string | undefined, root: string): string {
  const trimmed = base?.trim();
  if (!trimmed) {
    return path.join(root, '.playwright', `junit-${phase}.xml`);
  }
  const parsed = path.parse(trimmed);
  return path.join(parsed.dir, `${parsed.name}-${phase}${parsed.ext || '.xml'}`);
}

/** Skipped tests in one JUnit file; a missing or unreadable file counts as none. */
export function countJunitSkipped(file: string): number {
  let xml: string;
  try {
    xml = fs.readFileSync(file, 'utf-8');
  } catch {
    return 0;
  }
  const root = /<testsuites\b[^>]*\bskipped="(\d+)"/.exec(xml);
  if (root) {
    return Number.parseInt(root[1], 10);
  }
  return (xml.match(/<skipped\b/g) ?? []).length;
}

/** The skip budget over the whole run. Returns the failure message, or `undefined` when it holds. */
export function skipBudgetFailure(skipped: number, env: NodeJS.ProcessEnv): string | undefined {
  const budget = Number.parseInt(env.MAX_SKIPPED?.trim() ?? '', 10);
  if (Number.isFinite(budget)) {
    return skipped > budget
      ? `MAX_SKIPPED=${budget} exceeded - ${skipped} tests were skipped across both phases.` +
          ' Something the suite depends on is missing from this engine.'
      : undefined;
  }
  if (env.CI) {
    return (
      'MAX_SKIPPED is not set. In CI the skip budget is mandatory: set it to the number of' +
      ' skips this environment is expected to have, so a degrading engine fails the run.'
    );
  }
  return undefined;
}

/** Combined exit code: the first failing phase wins, then the skip budget, then the leak check. */
export function combinedExitCode(
  phaseCodes: readonly number[],
  skipBudgetFailed: boolean,
  leakClean: boolean
): number {
  const failed = phaseCodes.find((code) => code !== 0);
  if (failed !== undefined) {
    return failed;
  }
  return skipBudgetFailed || !leakClean ? 1 : 0;
}

export function hasProjectArg(passthrough: readonly string[]): boolean {
  return passthrough.some((arg) => arg === '--project' || arg.startsWith('--project='));
}

export function writeParallelismFile(root: string, value: unknown): string {
  const file = path.join(root, '.playwright', 'parallelism.json');
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`, 'utf-8');
  return file;
}
