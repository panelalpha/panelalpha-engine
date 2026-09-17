import type { FullResult, Reporter, TestCase, TestResult } from '@playwright/test/reporter';

/**
 * Makes skipped tests visible, and optionally budgeted.
 *
 * This suite skips a lot on purpose: CSF may not be installed, `pae-artisan`
 * may be unreachable, IP management is an optional module. That is the right
 * behaviour, but it means a green run says nothing about how much of the suite
 * actually executed — an engine that has quietly lost a component reports the
 * same "passed" as a healthy one.
 *
 * So: every run prints what it skipped and why, grouped by reason. Set
 * `MAX_SKIPPED` to a number to also fail the run when the count goes above it,
 * which is how CI notices the environment degrading.
 */
export default class SkipBudgetReporter implements Reporter {
  private readonly skipped: { title: string; reason: string }[] = [];
  private executed = 0;

  onTestEnd(test: TestCase, result: TestResult): void {
    if (result.status === 'skipped') {
      this.skipped.push({
        title: test.titlePath().filter(Boolean).join(' › '),
        reason: reasonOf(result),
      });
      return;
    }
    this.executed += 1;
  }

  onEnd(result: FullResult): Promise<{ status?: FullResult['status'] } | void> {
    if (this.skipped.length === 0) {
      return Promise.resolve();
    }

    const byReason = new Map<string, string[]>();
    for (const entry of this.skipped) {
      const titles = byReason.get(entry.reason) ?? [];
      titles.push(entry.title);
      byReason.set(entry.reason, titles);
    }

    const total = this.executed + this.skipped.length;
    console.log(`\n  Skipped ${this.skipped.length} of ${total} tests:`);
    for (const [reason, titles] of [...byReason.entries()].sort(
      (a, b) => b[1].length - a[1].length
    )) {
      console.log(`    ${titles.length}x  ${reason}`);
      for (const title of titles.slice(0, 3)) {
        console.log(`          ${title}`);
      }
      if (titles.length > 3) {
        console.log(`          … and ${titles.length - 3} more`);
      }
    }

    const budget = Number.parseInt(process.env.MAX_SKIPPED ?? '', 10);
    if (Number.isFinite(budget) && this.skipped.length > budget) {
      console.log(
        `\n  MAX_SKIPPED=${budget} exceeded — ${this.skipped.length} tests were skipped.` +
          ` Something the suite depends on is missing from this engine.`
      );
      return Promise.resolve({ status: 'failed' as const });
    }

    return Promise.resolve({ status: result.status });
  }
}

/** Playwright records a skip reason as an annotation, or leaves none for `test.skip()`. */
function reasonOf(result: TestResult): string {
  const annotation = result.annotations?.find((entry) => entry.type === 'skip');
  return annotation?.description?.trim() ?? 'no reason given';
}
