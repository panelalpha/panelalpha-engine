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
 * Warnings a partial deploy may carry on a test engine: the public name or the
 * certificate cannot be trusted from the internet (core
 * app/Lib/Domains/PublicUrl.php `unresolvableWarning` / `certificateWarning`,
 * joined by DeploymentWorkflow.php:131-153 `publicUrlWarnings`). Those depend on
 * the host's DNS and TLS, not on the application.
 *
 * Everything else makes a deploy partial because the application itself is wrong
 * and must fail the test: AppHealth::NOT_ANSWERING (no port answered),
 * AppHealth::edgeFailure (the webserver answers 5xx or refuses), a failed
 * error-severity health check (AppHealth::errorCheckMessages), or the custom
 * env hint (DeploymentWorkflow.php `customEnvFailureHint`).
 */
export const ACCEPTED_DEPLOYMENT_WARNINGS: readonly RegExp[] = [
  /^The application is deployed but not reachable from the internet: /,
  /^https:\/\/\S+ is served a certificate browsers will refuse \(/,
];

/** Warnings that are not one of the accepted public-name or certificate kinds. */
export function unacceptedDeploymentWarnings(warnings: readonly string[]): string[] {
  return warnings.filter((line) => !ACCEPTED_DEPLOYMENT_WARNINGS.some((kind) => kind.test(line)));
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
  // A clean success stores the status and leaves the list out. A partial
  // deploy always writes the sentences that made it partial.
  if (status === 'partial' || warnings != null) {
    expect(
      Array.isArray(warnings),
      'deployment_warnings must be a list after a finished deploy'
    ).toBe(true);
  }
  const list = Array.isArray(warnings) ? warnings : [];
  for (const line of list) {
    expect(typeof line).toBe('string');
    expect(line.length).toBeGreaterThan(0);
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
