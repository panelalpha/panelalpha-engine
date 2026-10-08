import { test, type TestInfo } from '@playwright/test';
import { redactAndTruncate } from './redact';

/** The parts of TestInfo this helper touches, so a unit test can pass a plain object. */
export type CleanupTestInfo = Pick<TestInfo, 'annotations' | 'attach'> &
  Partial<Pick<TestInfo, 'status' | 'errors'>>;

export interface CleanupStepOptions {
  /** Re-reads the state and returns whether the restore really took effect. */
  verify?: () => boolean | Promise<boolean>;
  /** What a failed verification should say, e.g. `mode is still "off", expected "on"`. */
  describe?: () => string;
}

export interface CleanupFailure {
  step: string;
  reason: string;
}

const REASON_MAX_CHARS = 400;
const FAILED_STATUSES = new Set(['failed', 'timedOut', 'interrupted']);

function reasonOf(error: unknown): string {
  const text = error instanceof Error ? error.message : String(error);
  return redactAndTruncate(text, REASON_MAX_CHARS);
}

/**
 * Teardown that cannot fail silently.
 *
 * Restoring shared state (the setup user, the global ModSecurity config) is
 * what keeps later tests honest, so a restore that throws, or that reports
 * success without the state actually coming back, has to show up in the run.
 *
 * Every step runs even when an earlier one failed. {@link finish} then throws
 * one error naming each failed step — unless the test already failed, in which
 * case the failures are only annotated and attached, so the original error
 * stays the one the report leads with.
 *
 * ```ts
 * const cleanup = new CleanupSteps(testInfo);
 * try {
 *   // test body
 * } catch (error) {
 *   cleanup.bodyFailed(error);
 *   throw error;
 * } finally {
 *   await cleanup.run('restore mode', () => api.setModSecurityConfig({ mode }), {
 *     verify: async () => (await api.getModSecurityConfig()).data.mode === mode,
 *   });
 *   await cleanup.finish();
 * }
 * ```
 *
 * In an `afterEach` hook the body has already finished, so `finish()` alone is
 * enough: it reads the test's status.
 */
export class CleanupSteps {
  private readonly info: CleanupTestInfo;
  private readonly failures: CleanupFailure[] = [];
  private bodyError = false;

  constructor(info: CleanupTestInfo = test.info()) {
    this.info = info;
  }

  /** Tells {@link finish} the test body threw, for use from a `catch` before the `finally`. */
  bodyFailed(_error?: unknown): void {
    this.bodyError = true;
  }

  /** The steps that failed so far. */
  get failed(): readonly CleanupFailure[] {
    return this.failures;
  }

  /**
   * Runs one teardown step. Never throws: a failure is recorded for {@link finish}.
   * Returns whether the step succeeded and, when given, verified.
   */
  async run(
    step: string,
    action: () => Promise<unknown> | void,
    options: CleanupStepOptions = {}
  ): Promise<boolean> {
    try {
      await action();
    } catch (error) {
      this.record(step, reasonOf(error));
      return false;
    }

    if (!options.verify) {
      return true;
    }

    try {
      if (await options.verify()) {
        return true;
      }
      const detail = options.describe ? ` (${options.describe()})` : '';
      this.record(step, reasonOf(`the state was not restored${detail}`));
    } catch (error) {
      this.record(step, reasonOf(`could not verify the restore: ${reasonOf(error)}`));
    }
    return false;
  }

  private record(step: string, reason: string): void {
    this.failures.push({ step, reason });
    this.info.annotations.push({ type: 'cleanup', description: `${step}: failed (${reason})` });
  }

  private testAlreadyFailed(): boolean {
    if (this.bodyError) {
      return true;
    }
    const { status, errors } = this.info;
    return (status !== undefined && FAILED_STATUSES.has(status)) || (errors?.length ?? 0) > 0;
  }

  /**
   * Reports the failed steps. Throws when the test body passed; otherwise only
   * annotates and attaches them, so the body's own error is not displaced.
   */
  async finish(): Promise<void> {
    if (this.failures.length === 0) {
      return;
    }

    const summary = this.failures.map(({ step, reason }) => `  - ${step}: ${reason}`).join('\n');
    const message = `${this.failures.length} cleanup step(s) failed:\n${summary}`;

    if (this.testAlreadyFailed()) {
      this.info.annotations.push({ type: 'cleanup-failed', description: message });
      await this.info.attach('cleanup-failures.txt', { body: message, contentType: 'text/plain' });
      return;
    }

    throw new Error(message);
  }
}
