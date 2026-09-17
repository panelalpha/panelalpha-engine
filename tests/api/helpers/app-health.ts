import { expect } from '@playwright/test';
import { appHealthSchema, SERVING_WORDS } from '@/schemas';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';
import type { AppHealth } from '@/types';

export { SERVING_WORDS };

/** Parse a live health payload and lock the serving vocabulary. */
export function parseAppHealth(data: unknown): AppHealth {
  return validateParsedApiResponse(data, appHealthSchema) as AppHealth;
}

export function healthFromRaw(body: unknown): AppHealth {
  const envelope = body as { data?: unknown };
  return parseAppHealth(envelope.data);
}

/**
 * A finished deploy writes `deployment_warnings` as a list of sentences.
 * `success` means none; `partial` means at least one.
 */
export function assertDeploymentWarnings(
  status: string | null | undefined,
  warnings: string[] | null | undefined
): void {
  if (status !== 'success' && status !== 'partial') {
    if (warnings != null) {
      expect(Array.isArray(warnings)).toBe(true);
    }
    return;
  }
  expect(
    Array.isArray(warnings),
    'deployment_warnings must be a list after a finished deploy'
  ).toBe(true);
  const list = warnings ?? [];
  for (const line of list) {
    expect(typeof line).toBe('string');
    expect(line.length).toBeGreaterThan(0);
  }
  if (status === 'success') {
    expect(list, 'a clean deploy must not carry serving warnings').toEqual([]);
  }
  if (status === 'partial') {
    expect(list.length, 'a partial deploy must name why').toBeGreaterThan(0);
  }
}

export function assertMissingEntry(report: AppHealth): void {
  expect(report.serving).toBe('missing_entry');
  const failed = report.checks.find(
    (check) => check.id === 'entry-served' && check.status === 'fail'
  );
  expect(failed, 'missing_entry must come from a failed entry-served check').toBeTruthy();
  expect(failed?.severity).toBe('error');
}
