import { expect, test } from '@/fixtures/test-options';
import { formatSeconds, msBetween, PhaseTimer, recordBuildTimings } from '@/helpers/phase-timer';

interface Annotation {
  type: string;
  description?: string;
}

function fixture(): { annotations: Annotation[]; steps: string[]; timer: PhaseTimer } {
  const annotations: Annotation[] = [];
  const steps: string[] = [];
  const timer = new PhaseTimer({ annotations }, async (name, body) => {
    steps.push(name);
    return body();
  });
  return { annotations, steps, timer };
}

test.describe('PhaseTimer', () => {
  test('run wraps the body in a step and annotates the duration', async () => {
    const { annotations, steps, timer } = fixture();
    const value = await timer.run('inspect', () => Promise.resolve(42));
    expect(value).toBe(42);
    expect(steps).toEqual(['inspect']);
    expect(annotations).toHaveLength(1);
    expect(annotations[0].type).toBe('phase');
    expect(annotations[0].description).toMatch(/^inspect \d+\.\d s$/);
  });

  test('a throwing body is annotated as failed and rethrown unchanged', async () => {
    const { annotations, timer } = fixture();
    const boom = new Error('boom');
    await expect(timer.run('create', () => Promise.reject(boom))).rejects.toBe(boom);
    expect(annotations[0].description).toMatch(/^create \d+\.\d s \(failed\)$/);
  });

  test('note adds a phase without a step and summary joins them in order', async () => {
    const { annotations, steps, timer } = fixture();
    await timer.run('inspect', () => Promise.resolve());
    timer.note('queue', 2500);
    expect(steps).toEqual(['inspect']);
    expect(annotations[1]).toEqual({ type: 'phase', description: 'queue 2.5 s' });
    expect(timer.summary()).toMatch(/^inspect \d+\.\d s · queue 2\.5 s$/);
  });

  test('note carries an extra label', () => {
    const { annotations, timer } = fixture();
    timer.note('task', 1000, 'failed');
    expect(annotations[0].description).toBe('task 1.0 s (failed)');
  });

  test('recordBuildTimings annotates the phases and attaches the JSON', async () => {
    const annotations: Annotation[] = [];
    const attached: string[] = [];
    const sink = {
      annotations,
      attach: (name: string) => {
        attached.push(name);
        return Promise.resolve();
      },
    };
    await recordBuildTimings(sink, { phases: [{ name: 'clone', seconds: 1.25 }] });
    expect(annotations).toEqual([{ type: 'build-timings', description: 'clone 1.3 s' }]);
    expect(attached).toEqual(['build-timings.json']);
  });

  test('empty timer has an empty summary', () => {
    expect(fixture().timer.summary()).toBe('');
  });
});

test.describe('phase helpers', () => {
  test('formatSeconds keeps one decimal', () => {
    expect(formatSeconds(12_340)).toBe('12.3 s');
  });

  test('msBetween returns undefined for missing or invalid stamps', () => {
    expect(msBetween('2026-01-01T00:00:00Z', '2026-01-01T00:00:03Z')).toBe(3000);
    expect(msBetween(undefined, '2026-01-01T00:00:03Z')).toBeUndefined();
    expect(msBetween('nope', '2026-01-01T00:00:03Z')).toBeUndefined();
    expect(msBetween('2026-01-01T00:00:03Z', '2026-01-01T00:00:00Z')).toBeUndefined();
  });
});
