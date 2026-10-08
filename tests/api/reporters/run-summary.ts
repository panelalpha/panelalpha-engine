import fs from 'node:fs';
import path from 'node:path';
import type {
  FullConfig,
  FullResult,
  Reporter,
  TestCase,
  TestResult,
} from '@playwright/test/reporter';
import { SKIP_CATEGORIES } from '../config/expected-skips';
import { redactText } from '../helpers/redact';
import { classifySkips, reasonOf, type SkipEntry } from './skip-classification';

/**
 * Prints, and writes to `<outputs>/summary.md`, what a long run leaves behind: which
 * tests failed and why, which projects were kept on the engine for inspection, how
 * the skips split by category, how long the supported-apps phases took, and the
 * outcome of the post-run leak check when this run's result is already written.
 *
 * It only reports. It never changes the run status.
 */
export interface RunSummaryOptions {
  /** Where `summary.md` goes and `leak-check/result.json` is read from. */
  dir?: string;
}

interface FailedTest {
  title: string;
  project: string;
  error: string;
}

interface LeakCheckResult {
  finishedAt?: string;
  ok?: boolean;
  leaks?: unknown[];
  changed?: unknown[];
  errors?: unknown[];
  allowed?: unknown[];
  removed?: unknown[];
}

const ERROR_LIMIT = 200;
const PHASE = /([a-z][\w-]*) (\d+(?:\.\d+)?) s/gi;
// eslint-disable-next-line no-control-regex
const ANSI = /\u001b\[[0-9;]*m/g;

export default class RunSummaryReporter implements Reporter {
  private readonly failed = new Map<string, FailedTest>();
  private readonly preserved = new Set<string>();
  private readonly skipped: SkipEntry[] = [];
  private readonly phaseTotals = new Map<string, number>();
  private readonly phaseTests = new Set<string>();
  private dir: string;
  private readonly explicit: boolean;
  private startedAt = Date.now();

  constructor(options: RunSummaryOptions = {}) {
    this.explicit = options.dir !== undefined;
    this.dir = options.dir ?? path.resolve('.playwright');
  }

  onBegin(config: FullConfig): void {
    this.startedAt = Date.now();
    const outputDir = config.projects?.[0]?.outputDir;
    // outputDir is `<base>/test-results[-env]`; the summary sits beside it.
    if (outputDir && !this.explicit) {
      this.dir = path.dirname(outputDir);
    }
  }

  onTestEnd(test: TestCase, result: TestResult): void {
    const key = test.id ?? test.titlePath().join('›');
    if (result.status === 'skipped') {
      this.skipped.push({
        title: test.titlePath().filter(Boolean).join(' › '),
        reason: reasonOf(result),
      });
    }

    if (result.status === 'failed' || result.status === 'timedOut') {
      this.failed.set(key, {
        title: test.titlePath().filter(Boolean).slice(1).join(' › '),
        project: test.parent?.project()?.name ?? '',
        error: errorLine(result),
      });
    } else {
      this.failed.delete(key);
    }

    for (const annotation of [...(test.annotations ?? []), ...(result.annotations ?? [])]) {
      if (annotation.type === 'preserved-users' && annotation.description) {
        for (const name of annotation.description.split(',')) {
          if (name.trim()) {
            this.preserved.add(name.trim());
          }
        }
      }
    }

    const phases = (result.annotations ?? test.annotations ?? []).find(
      (annotation) => annotation.type === 'phases'
    );
    if (phases?.description && !this.phaseTests.has(key)) {
      this.phaseTests.add(key);
      for (const match of phases.description.matchAll(PHASE)) {
        const name = match[1].toLowerCase();
        this.phaseTotals.set(name, (this.phaseTotals.get(name) ?? 0) + Number(match[2]));
      }
    }
  }

  onEnd(_result: FullResult): void {
    try {
      const text = this.render();
      console.log(`\n${text}`);
      fs.mkdirSync(this.dir, { recursive: true });
      fs.writeFileSync(path.join(this.dir, 'summary.md'), `${text}\n`);
    } catch (error) {
      console.log(
        `\n  Run summary could not be written: ${error instanceof Error ? error.message : String(error)}`
      );
    }
  }

  private render(): string {
    const lines: string[] = ['## Run summary', ''];

    lines.push(`Failed tests: ${this.failed.size}`);
    for (const test of this.failed.values()) {
      const project = test.project ? `[${test.project}] ` : '';
      lines.push(`- ${project}${test.title} — ${test.error}`);
    }

    lines.push('', `Projects kept for inspection: ${this.preserved.size}`);
    for (const name of this.preserved) {
      lines.push(
        `- ${name} — inspect with MCP project_get, delete with MCP project_delete or DELETE projects/${name}`
      );
    }

    lines.push('', `Skipped: ${this.skipped.length}`);
    if (this.skipped.length > 0) {
      const classified = classifySkips(this.skipped);
      for (const category of SKIP_CATEGORIES) {
        lines.push(`- ${category}: ${classified.byCategory[category]}`);
      }
      lines.push(`- UNEXPECTED: ${classified.unexpected.length}`);
    }

    if (this.phaseTotals.size > 0) {
      const total = [...this.phaseTotals.values()].reduce((sum, seconds) => sum + seconds, 0);
      const parts = [...this.phaseTotals.entries()].map(
        ([name, seconds]) => `${name} ${seconds.toFixed(1)} s`
      );
      lines.push(
        '',
        `Supported-apps phases (${this.phaseTests.size} tests): ${total.toFixed(1)} s total`,
        `- ${parts.join(' · ')}`
      );
    }

    lines.push('', this.leakCheckLine());
    return lines.join('\n');
  }

  private leakCheckPending(): string {
    const flag = process.env.LEAK_CHECK;
    if (flag === '1' || (process.env.CI && flag !== '0')) {
      return 'leak-check: runs after the tests when LEAK_CHECK=1 — see the end of the bun run test output';
    }
    return 'leak-check: not run';
  }

  private leakCheckLine(): string {
    const file = path.join(this.dir, 'leak-check', 'result.json');
    let parsed: LeakCheckResult;
    try {
      parsed = JSON.parse(fs.readFileSync(file, 'utf8')) as LeakCheckResult;
    } catch {
      return this.leakCheckPending();
    }
    const finished = Date.parse(parsed.finishedAt ?? '');
    if (!Number.isFinite(finished) || finished < this.startedAt) {
      // A leftover from an earlier run: this run's check happens after Playwright exits.
      return this.leakCheckPending();
    }
    const count = (list: unknown[] | undefined): number => (Array.isArray(list) ? list.length : 0);
    return (
      `leak-check: ${parsed.ok === true ? 'ok' : 'FAILED'} — ${count(parsed.leaks)} leaks, ` +
      `${count(parsed.changed)} changed, ${count(parsed.errors)} errors, ` +
      `${count(parsed.allowed)} allowed, ${count(parsed.removed)} removed`
    );
  }
}

/** First line of the failure, redacted and cut; a deploy failure keeps its `POST /projects task N ended` opening. */
function errorLine(result: TestResult): string {
  const message = result.error?.message ?? result.errors?.[0]?.message ?? '';
  const first =
    message
      .replace(ANSI, '')
      .split('\n')
      .map((line) => line.trim())
      .find((line) => line.length > 0) ?? 'no error message';
  const redacted = redactText(first);
  return redacted.length > ERROR_LIMIT ? `${redacted.slice(0, ERROR_LIMIT - 1)}…` : redacted;
}
