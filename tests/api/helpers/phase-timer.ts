import { test, type TestInfo } from '@playwright/test';

type StepRunner = <T>(name: string, body: () => Promise<T>) => Promise<T>;
type AnnotationSink = Pick<TestInfo, 'annotations'>;

interface Phase {
  name: string;
  label: string;
}

/** `12.3 s`, one decimal. */
export function formatSeconds(ms: number): string {
  return `${(ms / 1000).toFixed(1)} s`;
}

/** Milliseconds between two ISO timestamps, or undefined when either is absent or unparsable. */
export function msBetween(from?: string | null, to?: string | null): number | undefined {
  if (!from || !to) {
    return undefined;
  }
  const diff = Date.parse(to) - Date.parse(from);
  return Number.isFinite(diff) && diff >= 0 ? diff : undefined;
}

/**
 * Times the stages of a long test. Every stage is pushed to the test annotations
 * the moment it ends, so a timeout later on still leaves the earlier durations in the report.
 */
export class PhaseTimer {
  private readonly phases: Phase[] = [];

  constructor(
    private readonly testInfo: AnnotationSink,
    private readonly step: StepRunner = (name, body) => test.step(name, body)
  ) {}

  /** Runs `body` as a report step named `name` and records how long it took, also when it throws. */
  async run<T>(name: string, body: () => Promise<T>): Promise<T> {
    const started = Date.now();
    try {
      const result = await this.step(name, body);
      this.record(name, Date.now() - started);
      return result;
    } catch (error) {
      this.record(name, Date.now() - started, 'failed');
      throw error;
    }
  }

  /** Records a duration measured elsewhere (no step). */
  note(name: string, ms: number, extra?: string): void {
    this.record(name, ms, extra);
  }

  /** `inspect 12.3 s · create 80.1 s`, in the order recorded. */
  summary(): string {
    return this.phases.map((phase) => phase.label).join(' · ');
  }

  private record(name: string, ms: number, extra?: string): void {
    const label = `${name} ${formatSeconds(ms)}${extra ? ` (${extra})` : ''}`;
    this.phases.push({ name, label });
    this.testInfo.annotations.push({ type: 'phase', description: label });
  }
}

/** Engine build phases as one short annotation, plus the full timings as an attachment. */
export async function recordBuildTimings(
  testInfo: Pick<TestInfo, 'annotations' | 'attach'>,
  timings: { phases?: { name: string; seconds: number }[] } | null | undefined
): Promise<void> {
  if (!timings) {
    return;
  }
  const line = (timings.phases ?? [])
    .map((phase) => `${phase.name} ${phase.seconds.toFixed(1)} s`)
    .join(' · ');
  if (line) {
    testInfo.annotations.push({
      type: 'build-timings',
      description: line.length > 500 ? `${line.slice(0, 497)}...` : line,
    });
  }
  await testInfo.attach('build-timings.json', {
    body: JSON.stringify(timings, null, 2),
    contentType: 'application/json',
  });
}
