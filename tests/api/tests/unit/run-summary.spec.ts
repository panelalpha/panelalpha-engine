import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import type { FullConfig, FullResult, TestCase, TestResult } from '@playwright/test/reporter';
import { expect, test } from '@/fixtures/test-options';
import RunSummaryReporter from '../../reporters/run-summary';

interface Annotation {
  type: string;
  description?: string;
}

function fake(
  id: string,
  title: string,
  project: string,
  result: { status: TestResult['status']; message?: string; annotations?: Annotation[] }
): [TestCase, TestResult] {
  const annotations = result.annotations ?? [];
  const test = {
    id,
    titlePath: () => ['', project, 'file.spec.ts', title],
    parent: { project: () => ({ name: project }) },
    annotations,
  } as unknown as TestCase;
  const outcome = {
    status: result.status,
    annotations,
    error: result.message === undefined ? undefined : { message: result.message },
    errors: [],
  } as unknown as TestResult;
  return [test, outcome];
}

function summarise(
  feed: (reporter: RunSummaryReporter) => void,
  setup?: (dir: string) => void
): { output: string; file: string; dir: string; status: unknown } {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'run-summary-'));
  setup?.(dir);
  const lines: string[] = [];
  const original = console.log;
  console.log = (...args: unknown[]) => void lines.push(args.join(' '));
  let status: unknown;
  try {
    const reporter = new RunSummaryReporter({ dir });
    feed(reporter);
    status = reporter.onEnd({ status: 'failed' } as FullResult);
  } finally {
    console.log = original;
  }
  return {
    output: lines.join('\n'),
    file: fs.readFileSync(path.join(dir, 'summary.md'), 'utf8'),
    dir,
    status,
  };
}

test.describe('run summary', () => {
  test('lists a failed test with its project and the first error line', () => {
    const { output, file, status } = summarise((reporter) => {
      reporter.onTestEnd(
        ...fake('1', 'php: Servas deploys', 'supported-apps', {
          status: 'failed',
          message: '\u001b[31mPOST /projects task 41 ended failed\u001b[39m\nsecond line',
        })
      );
      reporter.onTestEnd(...fake('2', 'fine', 'api', { status: 'passed' }));
    });
    expect(output).toContain('Failed tests: 1');
    expect(output).toContain(
      '[supported-apps] file.spec.ts › php: Servas deploys — POST /projects task 41 ended failed'
    );
    expect(output).not.toContain('second line');
    expect(file).toContain('Failed tests: 1');
    expect(status).toBeUndefined();
  });

  test('a long error line is cut', () => {
    const { output } = summarise((reporter) =>
      reporter.onTestEnd(...fake('1', 't', 'api', { status: 'failed', message: 'x'.repeat(500) }))
    );
    const line = output.split('\n').find((entry) => entry.includes('— xxx')) ?? '';
    expect(line.length).toBeLessThan(300);
    expect(line.endsWith('…')).toBe(true);
  });

  test('a planted API_TOKEN never reaches the output or the file', () => {
    const saved = process.env.API_TOKEN;
    process.env.API_TOKEN = 'tok_PLANTEDSUMMARYTOKEN9876';
    try {
      const { output, file } = summarise((reporter) =>
        reporter.onTestEnd(
          ...fake('1', 't', 'api', {
            status: 'failed',
            message: 'GET projects failed with tok_PLANTEDSUMMARYTOKEN9876 in the header',
          })
        )
      );
      expect(output).not.toContain('PLANTEDSUMMARYTOKEN');
      expect(file).not.toContain('PLANTEDSUMMARYTOKEN');
    } finally {
      if (saved === undefined) {
        delete process.env.API_TOKEN;
      } else {
        process.env.API_TOKEN = saved;
      }
    }
  });

  test('preserved projects are listed once with a delete hint', () => {
    const { output } = summarise((reporter) => {
      reporter.onTestEnd(
        ...fake('1', 'a', 'api', {
          status: 'failed',
          message: 'boom',
          annotations: [{ type: 'preserved-users', description: 'pa-one, pa-two' }],
        })
      );
      reporter.onTestEnd(
        ...fake('2', 'b', 'api', {
          status: 'failed',
          message: 'boom',
          annotations: [{ type: 'preserved-users', description: 'pa-two' }],
        })
      );
    });
    expect(output).toContain('Projects kept for inspection: 2');
    expect(output).toContain('pa-one');
    expect(output).toContain('DELETE projects/pa-two');
    expect(output.match(/- pa-two/g)).toHaveLength(1);
  });

  test('skips are counted per category', () => {
    const skipped = (id: string, reason: string): [TestCase, TestResult] =>
      fake(id, id, 'api', {
        status: 'skipped',
        annotations: [{ type: 'skip', description: reason }],
      });
    const { output } = summarise((reporter) => {
      reporter.onTestEnd(...skipped('1', 'LiteSpeed Cache needs LiteSpeed or OpenLiteSpeed; x'));
      reporter.onTestEnd(...skipped('2', 'Set ALLOW_NETWORK_MUTATION=1 to do it.'));
      reporter.onTestEnd(...skipped('3', 'Something new.'));
    });
    expect(output).toContain('Skipped: 3');
    expect(output).toContain('- environment: 1');
    expect(output).toContain('- opt-in: 1');
    expect(output).toContain('- UNEXPECTED: 1');
  });

  test('phase durations are summed across tests', () => {
    const phased = (id: string, description: string): [TestCase, TestResult] =>
      fake(id, id, 'supported-apps', {
        status: 'passed',
        annotations: [{ type: 'phases', description }],
      });
    const { output } = summarise((reporter) => {
      reporter.onTestEnd(...phased('1', 'inspect 1.0 s · create 10.5 s · deploy 100.0 s'));
      reporter.onTestEnd(...phased('2', 'inspect 2.0 s · create 4.5 s · cleanup 3.0 s (failed)'));
    });
    expect(output).toContain('Supported-apps phases (2 tests): 121.0 s total');
    expect(output).toContain('inspect 3.0 s');
    expect(output).toContain('create 15.0 s');
  });

  test('without phases annotations there is no phases section', () => {
    const { output } = summarise((reporter) =>
      reporter.onTestEnd(...fake('1', 't', 'api', { status: 'passed' }))
    );
    expect(output).not.toContain('Supported-apps phases');
  });

  test('says the leak check did not run when there is no result file', () => {
    withEnv({ LEAK_CHECK: undefined, CI: undefined }, () => {
      expect(summarise(() => undefined).output).toContain('leak-check: not run');
    });
  });

  function writeLeakResult(dir: string, finishedAt: string): void {
    fs.mkdirSync(path.join(dir, 'leak-check'));
    fs.writeFileSync(
      path.join(dir, 'leak-check', 'result.json'),
      JSON.stringify({
        ok: false,
        finishedAt,
        leaks: [{ section: 'projects', key: 'pa-x', detail: 'left' }],
        changed: [],
        errors: [],
        allowed: [1, 2],
        removed: [],
      })
    );
  }

  function withEnv(values: Record<string, string | undefined>, run: () => void): void {
    const saved = Object.fromEntries(Object.keys(values).map((key) => [key, process.env[key]]));
    const apply = (source: Record<string, string | undefined>): void => {
      for (const [key, value] of Object.entries(source)) {
        if (value === undefined) {
          delete process.env[key];
        } else {
          process.env[key] = value;
        }
      }
    };
    apply(values);
    try {
      run();
    } finally {
      apply(saved);
    }
  }

  test('shows the leak check result written after this run began', () => {
    const { output } = summarise(
      (reporter) => reporter.onBegin({ projects: [] } as unknown as FullConfig),
      (dir) => writeLeakResult(dir, new Date(Date.now() + 60_000).toISOString())
    );
    expect(output).toContain(
      'leak-check: FAILED — 1 leaks, 0 changed, 0 errors, 2 allowed, 0 removed'
    );
  });

  test('ignores a leak check result left by an earlier run', () => {
    withEnv({ LEAK_CHECK: undefined, CI: undefined }, () => {
      const { output } = summarise(
        (reporter) => reporter.onBegin({ projects: [] } as unknown as FullConfig),
        (dir) => writeLeakResult(dir, new Date(Date.now() - 3_600_000).toISOString())
      );
      expect(output).toContain('leak-check: not run');
      expect(output).not.toContain('FAILED');
    });
  });

  test('the leak check line follows LEAK_CHECK and CI when no fresh result exists', () => {
    const pending =
      'leak-check: runs after the tests when LEAK_CHECK=1 — see the end of the bun run test output';
    const line = (env: Record<string, string | undefined>): string => {
      let output = '';
      withEnv(env, () => {
        output = summarise(() => undefined).output;
      });
      return output;
    };
    expect(line({ LEAK_CHECK: '1', CI: undefined })).toContain(pending);
    expect(line({ LEAK_CHECK: undefined, CI: 'true' })).toContain(pending);
    expect(line({ LEAK_CHECK: '0', CI: 'true' })).toContain('leak-check: not run');
    expect(line({ LEAK_CHECK: undefined, CI: undefined })).toContain('leak-check: not run');
  });

  test('an unreadable leak check result counts as not run', () => {
    withEnv({ LEAK_CHECK: undefined, CI: undefined }, () => {
      const { output } = summarise(
        () => undefined,
        (dir) => {
          fs.mkdirSync(path.join(dir, 'leak-check'));
          fs.writeFileSync(path.join(dir, 'leak-check', 'result.json'), '{not json');
        }
      );
      expect(output).toContain('leak-check: not run');
    });
  });
});
