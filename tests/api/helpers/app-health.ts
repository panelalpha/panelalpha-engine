import { expect } from '@playwright/test';
import { appHealthSchema, findingSchema, SERVING_WORDS } from '@/schemas';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';
import type { AppHealth, Finding } from '@/types';

export { SERVING_WORDS };

/** Parse a live health payload and lock the serving vocabulary. */
export function parseAppHealth(data: unknown): AppHealth {
  return validateParsedApiResponse(data, appHealthSchema) as AppHealth;
}

export function healthFromRaw(body: unknown): AppHealth {
  const envelope = body as { data?: unknown };
  return parseAppHealth(envelope.data);
}

/** Partial-deploy codes that depend on the test host's DNS and TLS, not on the application. */
export const ACCEPTED_DEPLOYMENT_WARNINGS: readonly string[] = [
  'public_url_unresolvable',
  'certificate_refused',
];

/** Warnings that are not one of the accepted public-name or certificate kinds. */
export function unacceptedDeploymentWarnings(warnings: readonly Finding[]): Finding[] {
  return warnings.filter((warning) => !ACCEPTED_DEPLOYMENT_WARNINGS.includes(warning.code));
}

/** A finished deploy's `deployment_warnings`: none on `success`, at least one on `partial`. */
export function assertDeploymentWarnings(
  status: string | null | undefined,
  warnings: Finding[] | null | undefined
): void {
  if (status !== 'success' && status !== 'partial') {
    if (warnings != null) {
      expect(Array.isArray(warnings)).toBe(true);
    }
    return;
  }
  // A clean success may leave the list out; a partial one always writes it.
  if (status === 'partial' || warnings != null) {
    expect(
      Array.isArray(warnings),
      'deployment_warnings must be a list after a finished deploy'
    ).toBe(true);
  }
  const list = Array.isArray(warnings) ? warnings : [];
  for (const warning of list) {
    validateParsedApiResponse(warning, findingSchema);
  }
  if (status === 'success') {
    expect(list, 'a clean deploy must not carry serving warnings').toEqual([]);
  }
  if (status === 'partial') {
    expect(list.length, 'a partial deploy must name why').toBeGreaterThan(0);
    expect(
      unacceptedDeploymentWarnings(list),
      'a partial deploy may only carry public-name or certificate warnings'
    ).toEqual([]);
  }
}

/**
 * The health payload of a project that must be serving: `serving` has to be
 * present (a 200 without it is a malformed report), and the whole payload is
 * parsed against the schema.
 */
export function requireServing(body: unknown): AppHealth {
  const data = (body as { data?: unknown } | null)?.data;
  const serving =
    data !== null && typeof data === 'object' ? (data as { serving?: unknown }).serving : undefined;
  expect(
    typeof serving === 'string' && serving.length > 0,
    `app health answered 200 without a serving word: ${JSON.stringify(body)}`
  ).toBe(true);
  return healthFromRaw(body);
}

export function assertMissingEntry(report: AppHealth): void {
  expect(report.serving).toBe('missing_entry');
  const failed = report.checks.find(
    (check) => check.id === 'entry-served' && check.status === 'fail'
  );
  expect(failed, 'missing_entry must come from a failed entry-served check').toBeTruthy();
  expect(failed?.severity).toBe('error');
}
