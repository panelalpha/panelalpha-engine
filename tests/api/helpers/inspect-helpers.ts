import type { InspectApplication, InspectReport } from '@/types';

function asRecord(value: unknown): Record<string, unknown> | undefined {
  return value !== null && typeof value === 'object'
    ? (value as Record<string, unknown>)
    : undefined;
}

/** Unwrap `{ data: InspectReport }` from inspect endpoints. */
export function inspectReportFromBody(body: unknown): InspectReport | undefined {
  const root = asRecord(body);
  const data = asRecord(root?.data) ?? root;
  if (data === undefined || asRecord(data.application) === undefined) {
    return undefined;
  }
  return data as InspectReport;
}

export function inspectApplication(body: unknown): InspectApplication | undefined {
  return inspectReportFromBody(body)?.application;
}

/** Recipe id the engine would deploy this source as (`wordpress`, `static`, …). */
export function inspectPlatform(body: unknown): string | undefined {
  const application = inspectApplication(body);
  if (typeof application?.platform === 'string' && application.platform.length > 0) {
    return application.platform;
  }
  const first = application?.candidates?.[0];
  if (typeof first?.id === 'string' && first.id.length > 0) {
    return first.id;
  }
  return undefined;
}

export function inspectStrategy(body: unknown): string | undefined {
  const strategy = inspectApplication(body)?.strategy;
  return typeof strategy === 'string' && strategy.length > 0 ? strategy : undefined;
}

/** Default branch inspect resolved for a git source, when the clone succeeded. */
export function inspectGitBranch(body: unknown): string | undefined {
  const source = asRecord(inspectReportFromBody(body)?.source);
  const git = asRecord(source?.git);
  const branch = git?.branch;
  return typeof branch === 'string' && branch.length > 0 ? branch : undefined;
}
