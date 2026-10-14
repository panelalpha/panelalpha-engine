/**
 * Shapes shared by the leak-check snapshot, the comparison and the CLI.
 *
 * A snapshot is plain JSON so it can be written to disk before a run and read
 * back after it, by this process or another.
 */

/** One listed thing (project, rule, subnet…). `owner` is the project it belongs to, when it has one. */
export interface SnapshotItem {
  owner?: string;
  [field: string]: unknown;
}

/** Things that exist or do not: anything new after the run is a leak. */
export interface ResourceSection {
  kind: 'resource';
  items: Record<string, SnapshotItem>;
  /** Per-item lookups that failed while building the section (e.g. one project's FTP list). */
  errors?: string[];
}

/** Settings: a value that differs after the run is a change. Values are redacted. */
export interface ConfigSection {
  kind: 'config';
  values: Record<string, unknown>;
}

export interface UnsupportedSection {
  kind: 'unsupported';
  reason: string;
}

export interface ErrorSection {
  kind: 'error';
  error: string;
}

export type Section = ResourceSection | ConfigSection | UnsupportedSection | ErrorSection;

export interface Snapshot {
  version: 1;
  takenAt: string;
  sections: Record<string, Section>;
}

export interface Leak {
  section: string;
  key: string;
  detail: string;
}

export interface Change {
  section: string;
  key: string;
  before: unknown;
  after: unknown;
}

export interface Removed {
  section: string;
  key: string;
}

export interface Allowed {
  section: string;
  key: string;
  reason: string;
}

export interface CompareError {
  section: string;
  side: 'before' | 'after' | 'run';
  error: string;
}

export interface CompareResult {
  ok: boolean;
  leaks: Leak[];
  changed: Change[];
  errors: CompareError[];
  allowed: Allowed[];
  /** Present before, gone after: informational, never fails the check. */
  removed: Removed[];
}

/** What `.playwright/leak-check/result.json` holds. */
export interface LeakCheckResult extends CompareResult {
  startedAt: string;
  finishedAt: string;
}
