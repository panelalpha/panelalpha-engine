/**
 * How many Playwright workers the suite may use against one Engine.
 *
 * Off by default: one worker, as it always was. `TEST_PARALLEL=auto` sizes the run to
 * the Engine host; `TEST_PARALLEL=<n>` fixes the API worker count. The decision is made
 * once, by `scripts/run-tests.ts`, which hands it to Playwright through `PW_API_WORKERS`
 * and `PW_APPS_WORKERS`, so the config itself never calls the network.
 */

export type ParallelMode = 'off' | 'auto' | 'fixed';

export type QueueSource = 'ENGINE_QUEUE_WORKERS' | 'engine-rule' | 'unknown';

export interface ParallelismInput {
  env: NodeJS.ProcessEnv;
  /** `MemTotal` of the Engine host, in bytes. Absent when it could not be read. */
  engineRamBytes?: number;
  /** CPUs of the machine that runs the suite. */
  cpus: number;
}

export interface Parallelism {
  mode: ParallelMode;
  /** Workers for `api-parallel` (and the setup users to provision). */
  apiWorkers: number;
  /** Workers for the Supported-board catalogue. Never above the Engine's queue workers. */
  appsWorkers: number;
  source: {
    ramGb: number | undefined;
    cpus: number;
    queueWorkers: number;
    queueSource: QueueSource;
  };
  /** Why the result is what it is when that is not obvious (unknown RAM, bad value, clamps). */
  reason: string;
}

const GIB = 1024 ** 3;
const MIB = 1024 ** 2;

/** core/app/Support/QueueWorkers.php: SMALL_HOST_MB. */
const ENGINE_SMALL_HOST_MB = 4096;
const MAX_AUTO_API_WORKERS = 4;
/** Host memory one API worker is budgeted. */
const GB_PER_API_WORKER = 4;

function positiveInt(raw: string | undefined): number | undefined {
  const text = raw?.trim() ?? '';
  if (!/^\d+$/.test(text)) {
    return undefined;
  }
  const value = Number.parseInt(text, 10);
  return value >= 1 ? value : undefined;
}

/**
 * The Engine's own default (QueueWorkers::defaultFor): one worker below 4 GB, two
 * otherwise. The Engine truncates MemTotal to whole MB before comparing, so does this.
 */
export function engineQueueWorkersFor(ramBytes: number): number {
  return Math.floor(ramBytes / MIB) < ENGINE_SMALL_HOST_MB ? 1 : 2;
}

function queueWorkers(
  env: NodeJS.ProcessEnv,
  ramBytes: number | undefined
): { workers: number; source: QueueSource } {
  const configured = positiveInt(env.ENGINE_QUEUE_WORKERS);
  if (configured !== undefined) {
    return { workers: configured, source: 'ENGINE_QUEUE_WORKERS' };
  }
  if (ramBytes === undefined) {
    // Cannot tell: assume the smaller of the two Engine defaults.
    return { workers: 1, source: 'unknown' };
  }
  return { workers: engineQueueWorkersFor(ramBytes), source: 'engine-rule' };
}

export function resolveParallelism(input: ParallelismInput): Parallelism {
  const { env } = input;
  const cpus = Math.max(1, Math.floor(input.cpus));
  const ramBytes =
    input.engineRamBytes !== undefined &&
    Number.isFinite(input.engineRamBytes) &&
    input.engineRamBytes > 0
      ? input.engineRamBytes
      : undefined;
  const ramGb = ramBytes === undefined ? undefined : ramBytes / GIB;
  const queue = queueWorkers(env, ramBytes);
  const source = {
    ramGb: ramGb === undefined ? undefined : Math.round(ramGb * 100) / 100,
    cpus,
    queueWorkers: queue.workers,
    queueSource: queue.source,
  };

  const raw = env.TEST_PARALLEL?.trim().toLowerCase() ?? '';
  if (raw === '' || raw === '0' || raw === 'off' || raw === 'false') {
    return {
      mode: 'off',
      apiWorkers: 1,
      appsWorkers: 1,
      source,
      reason: 'TEST_PARALLEL is not set',
    };
  }

  if (raw === 'auto') {
    if (ramGb === undefined) {
      return {
        mode: 'auto',
        apiWorkers: 1,
        appsWorkers: 1,
        source,
        reason: 'Engine RAM is unknown (metrics unreachable), so the run stays on one worker',
      };
    }
    // MemTotal sits a little under what the host is sold as (an 8 GB VM reports about 7.6 GB),
    // so the size is rounded to whole GB first.
    const nominalGb = Math.round(ramGb);
    const byRam = Math.min(
      MAX_AUTO_API_WORKERS,
      Math.max(1, Math.floor(nominalGb / GB_PER_API_WORKER))
    );
    const apiWorkers = Math.min(byRam, cpus);
    const appsWorkers = Math.min(queue.workers, apiWorkers);
    const limits: string[] = [`${ramGb.toFixed(1)} GB RAM allows ${byRam}`];
    if (apiWorkers < byRam) {
      limits.push(`${cpus} CPU(s) here cap it at ${apiWorkers}`);
    }
    return {
      mode: 'auto',
      apiWorkers,
      appsWorkers,
      source,
      reason: `${limits.join(', ')}; Engine queue has ${queue.workers} worker(s)`,
    };
  }

  const fixed = positiveInt(raw);
  if (fixed === undefined) {
    return {
      mode: 'off',
      apiWorkers: 1,
      appsWorkers: 1,
      source,
      reason: `TEST_PARALLEL="${raw}" is not off, auto or a number, so the run stays on one worker`,
    };
  }
  return {
    mode: 'fixed',
    apiWorkers: fixed,
    appsWorkers: Math.min(fixed, queue.workers),
    source,
    reason:
      queue.source === 'unknown'
        ? `TEST_PARALLEL=${fixed}; Engine queue workers unknown, so the catalogue runs on 1`
        : `TEST_PARALLEL=${fixed}; Engine queue has ${queue.workers} worker(s)`,
  };
}

/** One line for the start of a run. */
export function describeParallelism(result: Parallelism): string {
  const { source } = result;
  const ram = source.ramGb === undefined ? 'unknown' : `${source.ramGb} GB`;
  return (
    `Parallelism ${result.mode}: api ${result.apiWorkers}, supported-apps ${result.appsWorkers} ` +
    `(Engine RAM ${ram}, ${source.cpus} CPU(s), queue workers ${source.queueWorkers} ` +
    `from ${source.queueSource}) - ${result.reason}`
  );
}

export type Phase = 'parallel' | 'serial';

/** What the runner decided, as the Playwright config and the specs see it. */
export interface PhaseEnv {
  /** Workers for `api-parallel` and the number of setup users. 1 means no split. */
  apiWorkers: number;
  appsWorkers: number;
  phase: Phase | undefined;
}

export function parallelismFromEnv(env: NodeJS.ProcessEnv = process.env): PhaseEnv {
  const apiWorkers = positiveInt(env.PW_API_WORKERS) ?? 1;
  const appsWorkers = Math.min(positiveInt(env.PW_APPS_WORKERS) ?? 1, apiWorkers);
  const phase = env.PW_PHASE?.trim();
  return {
    apiWorkers,
    appsWorkers,
    phase: phase === 'parallel' || phase === 'serial' ? phase : undefined,
  };
}

/** The suite is split into parallel and serial parts only when more than one worker is chosen. */
export function parallelismEnabled(env: NodeJS.ProcessEnv = process.env): boolean {
  return parallelismFromEnv(env).apiWorkers > 1;
}

/** The environment the runner hands to a Playwright phase. */
export function phaseEnvironment(result: Parallelism, phase: Phase): Record<string, string> {
  return {
    PW_API_WORKERS: String(result.apiWorkers),
    PW_APPS_WORKERS: String(result.appsWorkers),
    PW_PHASE: phase,
  };
}
