import type { FullResult, Reporter, TestCase, TestResult } from '@playwright/test/reporter';
import { SKIP_CATEGORIES } from '../config/expected-skips';
import { classifySkips, reasonOf, type SkipEntry } from './skip-classification';

/**
 * Makes skipped tests visible, and budgeted.
 *
 * This suite skips a lot on purpose: the firewall (ufw) may not be installed, `pae-artisan`
 * may be unreachable, IP management is an optional module. That is the right
 * behaviour, but it means a green run says nothing about how much of the suite
 * actually executed — an engine that has quietly lost a component reports the
 * same "passed" as a healthy one.
 *
 * So: every run prints what it skipped and why, grouped by reason, and sorts each
 * reason into config/expected-skips.ts (acceptable) or UNEXPECTED. With
 * `SKIP_POLICY=strict`, or `CI` set, an unexpected reason or a reason above its
 * `max` fails the run; otherwise it is a warning. `MAX_SKIPPED` fails the run when
 * the count goes above it, and is mandatory in CI, which is how CI notices the
 * environment degrading.
 */
export interface SkipBudgetOptions {
  env?: NodeJS.ProcessEnv;
}

export default class SkipBudgetReporter implements Reporter {
  private readonly skipped: SkipEntry[] = [];
  private readonly projects = new Set<string>();
  private readonly env: NodeJS.ProcessEnv;
  private executed = 0;

  constructor(options: SkipBudgetOptions = {}) {
    this.env = options.env ?? process.env;
  }

  onTestEnd(test: TestCase, result: TestResult): void {
    const project = test.parent?.project()?.name;
    if (project) {
      this.projects.add(project);
    }
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
    const inCi = Boolean(this.env.CI);
    const strict = inCi || this.env.SKIP_POLICY?.trim().toLowerCase() === 'strict';
    const failures: string[] = [];

    if (this.skipped.length > 0) {
      this.printGroups();
      this.printClassification(strict, failures);
    }

    const rawBudget = this.env.MAX_SKIPPED?.trim() ?? '';
    const budget = Number.parseInt(rawBudget, 10);
    if (Number.isFinite(budget)) {
      if (this.skipped.length > budget) {
        failures.push(
          `MAX_SKIPPED=${budget} exceeded — ${this.skipped.length} tests were skipped.` +
            ` Something the suite depends on is missing from this engine.`
        );
      }
    } else if (inCi && !this.onlyUnitProject()) {
      failures.push(
        'MAX_SKIPPED is not set. In CI the skip budget is mandatory: set it to the number of' +
          ' skips this environment is expected to have, so a degrading engine fails the run.'
      );
    }

    if (failures.length > 0) {
      for (const failure of failures) {
        console.log(`\n  ${failure}`);
      }
      return Promise.resolve({ status: 'failed' as const });
    }

    return Promise.resolve({ status: result.status });
  }

  /** `bun run test:unit` needs no engine, so it has no skip budget to hold it to. */
  private onlyUnitProject(): boolean {
    return this.projects.size > 0 && [...this.projects].every((name) => name === 'unit');
  }

  private printGroups(): void {
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
  }

  private printClassification(strict: boolean, failures: string[]): void {
    const classified = classifySkips(this.skipped);

    console.log('\n  Skip classification:');
    for (const category of SKIP_CATEGORIES) {
      console.log(`    ${category}: ${classified.byCategory[category]}`);
      for (const group of classified.expected.filter((entry) => entry.rule.category === category)) {
        const limit = group.rule.max === undefined ? '' : ` (max ${group.rule.max})`;
        console.log(`      ${group.entries.length}x  ${group.rule.id}${limit}`);
      }
    }
    console.log(`    UNEXPECTED: ${classified.unexpected.length}`);
    for (const entry of classified.unexpected) {
      console.log(`      ${entry.title}`);
      console.log(`          ${entry.reason}`);
    }

    const problems: string[] = [];
    if (classified.unexpected.length > 0) {
      problems.push(
        `${classified.unexpected.length} tests were skipped for a reason that is not in` +
          ` config/expected-skips.ts.`
      );
    }
    for (const group of classified.overMax) {
      problems.push(
        `"${group.rule.id}" skipped ${group.entries.length} tests; at most ${group.rule.max}` +
          ` are accepted.`
      );
    }
    for (const problem of problems) {
      if (strict) {
        failures.push(problem);
      } else {
        console.log(`\n  WARNING: ${problem} (fails with SKIP_POLICY=strict or in CI)`);
      }
    }
  }
}
