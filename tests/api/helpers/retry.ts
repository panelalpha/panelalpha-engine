import { expect } from '@playwright/test';

export interface WaitForConditionOptions {
  timeout?: number;
  interval?: number;
  message?: string;
  /** Included in the poll failure when the condition never becomes true. */
  describeLast?: () => string | Promise<string>;
}

/**
 * Polls until `condition` returns true.
 *
 * Implemented with `expect.poll`, so a thrown error aborts immediately and a
 * false result is retried until `timeout`. Prefer calling `expect.poll` from
 * the spec when the value under test is the assertion itself.
 */
export async function waitForCondition(
  condition: () => Promise<boolean>,
  options: WaitForConditionOptions = {}
): Promise<void> {
  const {
    timeout = 30000,
    interval = 1000,
    message = 'Condition not met within timeout',
    describeLast,
  } = options;

  await expect
    .poll(
      async () => {
        if (await condition()) {
          return 'met';
        }
        if (!describeLast) {
          return 'not met';
        }
        return `not met. Last state: ${await describeLast()}`;
      },
      {
        message,
        timeout,
        intervals: [interval],
      }
    )
    .toBe('met');
}

/**
 * Fixed pause between mutations that have no readable condition yet
 * (for example a daemon reload the API does not report).
 */
export function delay(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
