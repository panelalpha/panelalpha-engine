import type { FullResult, TestCase, TestResult } from '@playwright/test/reporter';
import { EXPECTED_SKIPS } from '@/config/expected-skips';
import { expect, test } from '@/fixtures/test-options';
import { classifySkips } from '../../reporters/skip-classification';
import SkipBudgetReporter from '../../reporters/skip-budget';

const LSCACHE = 'LiteSpeed Cache needs LiteSpeed or OpenLiteSpeed; this engine runs nginx-proxy.';
const SURPRISE = 'The fixture vanished.';

function skip(reason: string | undefined, title = 'a test'): [TestCase, TestResult] {
  const test = {
    titlePath: () => ['', 'api', title],
    parent: { project: () => ({ name: 'api' }) },
  } as unknown as TestCase;
  const result = {
    status: 'skipped',
    annotations: reason === undefined ? [] : [{ type: 'skip', description: reason }],
  } as unknown as TestResult;
  return [test, result];
}

async function run(
  reasons: (string | undefined)[],
  env: NodeJS.ProcessEnv,
  runStatus: FullResult['status'] = 'passed'
): Promise<{ status?: FullResult['status']; output: string }> {
  const lines: string[] = [];
  const original = console.log;
  console.log = (...args: unknown[]) => void lines.push(args.join(' '));
  try {
    const reporter = new SkipBudgetReporter({ env });
    reasons.forEach((reason, index) => reporter.onTestEnd(...skip(reason, `test ${index}`)));
    const outcome = await reporter.onEnd({ status: runStatus } as FullResult);
    return { status: outcome?.status, output: lines.join('\n') };
  } finally {
    console.log = original;
  }
}

test.describe('skip classification', () => {
  test('a known reason is expected and carries its category', () => {
    const result = classifySkips([{ title: 't', reason: LSCACHE }]);
    expect(result.unexpected).toEqual([]);
    expect(result.expected[0].rule.id).toBe('lscache-needs-litespeed');
    expect(result.byCategory.environment).toBe(1);
  });

  test('a reason from skipTest() is matched without its [skip] prefix', () => {
    const result = classifySkips([{ title: 't', reason: `[skip] ${LSCACHE}` }]);
    expect(result.unexpected).toEqual([]);
  });

  test('an unknown reason and a missing reason are unexpected', () => {
    const result = classifySkips([
      { title: 'a', reason: SURPRISE },
      { title: 'b', reason: 'no reason given' },
    ]);
    expect(result.unexpected.map((entry) => entry.title)).toEqual(['a', 'b']);
  });

  test('a reason above its max is reported as over the limit', () => {
    const reason = 'The engine IP answered 301, so its source address is not private here.';
    const result = classifySkips([
      { title: 'a', reason },
      { title: 'b', reason },
    ]);
    expect(result.unexpected).toEqual([]);
    expect(result.overMax.map((group) => group.rule.id)).toEqual(['private-ip-engine-ip-answered']);
  });

  test('every rule has a unique id and a reason', () => {
    const ids = EXPECTED_SKIPS.map((rule) => rule.id);
    expect(new Set(ids).size).toBe(ids.length);
    expect(EXPECTED_SKIPS.every((rule) => rule.why.length > 0)).toBe(true);
  });
});

test.describe('skip-budget reporter', () => {
  test('an unexpected reason only warns outside strict mode', async () => {
    const { status, output } = await run([LSCACHE, SURPRISE], {});
    expect(status).toBe('passed');
    expect(output).toContain('WARNING');
    expect(output).toContain('UNEXPECTED: 1');
    expect(output).toContain('test 1');
  });

  test('an unexpected reason fails with SKIP_POLICY=strict', async () => {
    const { status, output } = await run([LSCACHE, SURPRISE], { SKIP_POLICY: 'strict' });
    expect(status).toBe('failed');
    expect(output).toContain('config/expected-skips.ts');
  });

  test('only expected reasons pass in strict mode', async () => {
    const { status } = await run([LSCACHE], { SKIP_POLICY: 'strict' });
    expect(status).toBe('passed');
  });

  test('a reason above its max fails in strict mode and warns otherwise', async () => {
    const reason = 'The engine IP answered 301, so its source address is not private here.';
    expect((await run([reason, reason], { SKIP_POLICY: 'strict' })).status).toBe('failed');
    const lax = await run([reason, reason], {});
    expect(lax.status).toBe('passed');
    expect(lax.output).toContain('at most 1');
  });

  test('MAX_SKIPPED still fails a run above the budget', async () => {
    expect((await run([LSCACHE, LSCACHE], { MAX_SKIPPED: '1' })).status).toBe('failed');
    expect((await run([LSCACHE, LSCACHE], { MAX_SKIPPED: '2' })).status).toBe('passed');
  });

  test('CI without MAX_SKIPPED fails with a clear message', async () => {
    const { status, output } = await run([LSCACHE], { CI: '1' });
    expect(status).toBe('failed');
    expect(output).toContain('MAX_SKIPPED is not set');
  });

  test('CI with MAX_SKIPPED and only expected skips passes', async () => {
    expect((await run([LSCACHE], { CI: '1', MAX_SKIPPED: '5' })).status).toBe('passed');
  });

  test('CI without any skips still needs MAX_SKIPPED', async () => {
    expect((await run([], { CI: '1' })).status).toBe('failed');
  });

  test('a failed run keeps its status when nothing else is wrong', async () => {
    expect((await run([LSCACHE], {}, 'failed')).status).toBe('failed');
  });
});
