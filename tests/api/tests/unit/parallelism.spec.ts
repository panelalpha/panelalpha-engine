import { expect, test } from '@/fixtures/test-options';
import {
  describeParallelism,
  engineQueueWorkersFor,
  parallelismEnabled,
  parallelismFromEnv,
  phaseEnvironment,
  resolveParallelism,
} from '@/config/parallelism';

/** How many workers the suite asks for, from the Engine host and the machine running it. */

const GIB = 1024 ** 3;

function resolve(
  env: NodeJS.ProcessEnv,
  ramGb: number | undefined,
  cpus: number
): ReturnType<typeof resolveParallelism> {
  return resolveParallelism({
    env,
    engineRamBytes: ramGb === undefined ? undefined : ramGb * GIB,
    cpus,
  });
}

test.describe('resolveParallelism', () => {
  test('off without TEST_PARALLEL, whatever the host is', () => {
    for (const value of [undefined, '', '0', 'off', 'OFF', 'false']) {
      const result = resolve(value === undefined ? {} : { TEST_PARALLEL: value }, 64, 32);
      expect(result.mode).toBe('off');
      expect([result.apiWorkers, result.appsWorkers]).toEqual([1, 1]);
    }
  });

  test('auto: the host sizes the run', () => {
    const auto = { TEST_PARALLEL: 'auto' };
    const cases: [string, number, number, number, number][] = [
      // label, ramGb, cpus, api, apps
      ['4 GB', 4, 4, 1, 1],
      ['4 GB host reporting 3.8 GB', 3.8, 4, 1, 1],
      ['8 GB', 8, 8, 2, 2],
      ['8 GB host reporting 7.6 GB', 7.6, 8, 2, 2],
      ['12 GB', 12, 8, 3, 2],
      ['32 GB, 16 CPUs', 32, 16, 4, 2],
      ['128 GB is still capped', 128, 64, 4, 2],
      ['32 GB but 2 CPUs here', 32, 2, 2, 2],
      ['32 GB but 1 CPU here', 32, 1, 1, 1],
    ];
    for (const [label, ramGb, cpus, api, apps] of cases) {
      const result = resolve(auto, ramGb, cpus);
      expect([label, result.mode, result.apiWorkers, result.appsWorkers]).toEqual([
        label,
        'auto',
        api,
        apps,
      ]);
    }
  });

  test('auto: ENGINE_QUEUE_WORKERS replaces the Engine rule and raises the catalogue workers', () => {
    const result = resolve({ TEST_PARALLEL: 'auto', ENGINE_QUEUE_WORKERS: '4' }, 32, 16);
    expect([result.apiWorkers, result.appsWorkers]).toEqual([4, 4]);
    expect(result.source.queueWorkers).toBe(4);
    expect(result.source.queueSource).toBe('ENGINE_QUEUE_WORKERS');

    const capped = resolve({ TEST_PARALLEL: 'auto', ENGINE_QUEUE_WORKERS: '1' }, 32, 16);
    expect([capped.apiWorkers, capped.appsWorkers]).toEqual([4, 1]);
  });

  test('auto: unknown RAM stays on one worker and says why', () => {
    const result = resolve({ TEST_PARALLEL: 'auto' }, undefined, 16);
    expect([result.mode, result.apiWorkers, result.appsWorkers]).toEqual(['auto', 1, 1]);
    expect(result.source.ramGb).toBeUndefined();
    expect(result.source.queueSource).toBe('unknown');
    expect(result.reason).toMatch(/unknown/i);
    expect(
      resolveParallelism({ env: { TEST_PARALLEL: 'auto' }, engineRamBytes: Number.NaN, cpus: 8 })
        .apiWorkers
    ).toBe(1);
  });

  test('a number fixes the API workers; the catalogue follows the queue', () => {
    const big = resolve({ TEST_PARALLEL: '3' }, 32, 2);
    expect([big.mode, big.apiWorkers, big.appsWorkers]).toEqual(['fixed', 3, 2]);

    const small = resolve({ TEST_PARALLEL: '3' }, 3.8, 2);
    expect([small.apiWorkers, small.appsWorkers]).toEqual([3, 1]);

    const unknown = resolve({ TEST_PARALLEL: '2' }, undefined, 2);
    expect([unknown.apiWorkers, unknown.appsWorkers]).toEqual([2, 1]);
    expect(unknown.reason).toMatch(/unknown/i);

    const queued = resolve({ TEST_PARALLEL: '2', ENGINE_QUEUE_WORKERS: '8' }, undefined, 2);
    expect([queued.apiWorkers, queued.appsWorkers]).toEqual([2, 2]);
  });

  test('a value that is not off, auto or a number leaves the run on one worker', () => {
    for (const value of ['yes', '-2', '1.5', 'many']) {
      const result = resolve({ TEST_PARALLEL: value }, 32, 16);
      expect([result.mode, result.apiWorkers, result.appsWorkers]).toEqual(['off', 1, 1]);
      expect(result.reason).toContain(value);
    }
  });

  test('the queue rule is the Engine one: under 4096 MB is one worker, else two', () => {
    expect(engineQueueWorkersFor(4096 * 1024 ** 2)).toBe(2);
    expect(engineQueueWorkersFor(4096 * 1024 ** 2 - 1024 ** 2)).toBe(1);
    // Whole MB are compared, as in QueueWorkers::defaultFor.
    expect(engineQueueWorkersFor(4096 * 1024 ** 2 - 1)).toBe(1);
    expect(resolve({ TEST_PARALLEL: 'auto' }, 3.5, 4).source.queueSource).toBe('engine-rule');
  });

  test('the source and the one-line description carry the inputs', () => {
    const result = resolve({ TEST_PARALLEL: 'auto' }, 8, 4);
    expect(result.source).toEqual({
      ramGb: 8,
      cpus: 4,
      queueWorkers: 2,
      queueSource: 'engine-rule',
    });
    expect(describeParallelism(result)).toContain('api 2, supported-apps 2');
  });
});

test.describe('what the runner hands to Playwright', () => {
  test('an environment without the runner variables is a single-worker run', () => {
    expect(parallelismFromEnv({})).toEqual({ apiWorkers: 1, appsWorkers: 1, phase: undefined });
    expect(parallelismEnabled({})).toBe(false);
    expect(parallelismEnabled({ PW_API_WORKERS: '1' })).toBe(false);
  });

  test('the phase environment round-trips', () => {
    const result = resolve({ TEST_PARALLEL: 'auto' }, 32, 16);
    const env = phaseEnvironment(result, 'serial');
    expect(env).toEqual({ PW_API_WORKERS: '4', PW_APPS_WORKERS: '2', PW_PHASE: 'serial' });
    expect(parallelismFromEnv(env)).toEqual({ apiWorkers: 4, appsWorkers: 2, phase: 'serial' });
    expect(parallelismEnabled(env)).toBe(true);
  });

  test('the catalogue never gets more workers than the API suite', () => {
    expect(parallelismFromEnv({ PW_API_WORKERS: '2', PW_APPS_WORKERS: '6' }).appsWorkers).toBe(2);
    expect(parallelismFromEnv({ PW_API_WORKERS: 'x', PW_APPS_WORKERS: '3' })).toMatchObject({
      apiWorkers: 1,
      appsWorkers: 1,
    });
  });
});
