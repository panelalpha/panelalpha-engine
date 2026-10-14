import { redactText } from '@/helpers/redact';
import { matchesEntry, type AllowList } from './allow';
import type {
  Allowed,
  Change,
  CompareError,
  CompareResult,
  Leak,
  Removed,
  Section,
  Snapshot,
  SnapshotItem,
} from './types';

export interface CompareOptions {
  allow: AllowList;
}

const DETAIL_LIMIT = 300;

function describeItem(item: unknown): string {
  const text = redactText(JSON.stringify(item) ?? '');
  return text.length > DETAIL_LIMIT ? `${text.slice(0, DETAIL_LIMIT)}…` : text;
}

function sameValue(a: unknown, b: unknown): boolean {
  return JSON.stringify(a) === JSON.stringify(b);
}

function allowReason(
  allow: AllowList,
  section: string,
  key: string,
  item: SnapshotItem | undefined
): string | undefined {
  const entry = allow.entries.find((candidate) => matchesEntry(candidate, section, key, item));
  if (entry) {
    return entry.reason;
  }
  const user = item?.owner ?? (section === 'projects' ? key : undefined);
  if (user !== undefined && allow.users.includes(user)) {
    return `owned by the shared setup project ${user}`;
  }
  return undefined;
}

function problem(
  snapshotSection: string,
  side: 'before' | 'after',
  section: Section
): CompareError[] {
  if (section.kind === 'error') {
    return [{ section: snapshotSection, side, error: section.error }];
  }
  if (section.kind === 'resource' && section.errors?.length) {
    return section.errors.map((error) => ({ section: snapshotSection, side, error }));
  }
  return [];
}

/**
 * Diffs two snapshots. A resource that appears after the run and is not
 * allowed is a leak; a config value that differs is a change; a section that
 * could not be read on either side is an error. The check is `ok` only when
 * there is none of those.
 */
export function compareSnapshots(
  before: Snapshot,
  after: Snapshot,
  options: CompareOptions
): CompareResult {
  const leaks: Leak[] = [];
  const changed: Change[] = [];
  const errors: CompareError[] = [];
  const allowed: Allowed[] = [];
  const removed: Removed[] = [];

  const names = new Set([...Object.keys(before.sections), ...Object.keys(after.sections)]);

  for (const name of [...names].sort()) {
    const was = before.sections[name];
    const now = after.sections[name];

    if (was) {
      errors.push(...problem(name, 'before', was));
    }
    if (now) {
      errors.push(...problem(name, 'after', now));
    }
    if (!was || !now) {
      continue;
    }

    if (was.kind === 'resource' && now.kind === 'resource') {
      for (const [key, item] of Object.entries(now.items)) {
        if (key in was.items) {
          continue;
        }
        const reason = allowReason(options.allow, name, key, item);
        if (reason) {
          allowed.push({ section: name, key, reason });
        } else {
          leaks.push({ section: name, key, detail: describeItem(item) });
        }
      }
      for (const key of Object.keys(was.items)) {
        if (!(key in now.items)) {
          removed.push({ section: name, key });
        }
      }
    } else if (was.kind === 'config' && now.kind === 'config') {
      for (const key of new Set([...Object.keys(was.values), ...Object.keys(now.values)])) {
        if (sameValue(was.values[key], now.values[key])) {
          continue;
        }
        const reason = allowReason(options.allow, name, key, undefined);
        if (reason) {
          allowed.push({ section: name, key, reason });
        } else {
          changed.push({ section: name, key, before: was.values[key], after: now.values[key] });
        }
      }
    }
  }

  return {
    ok: leaks.length === 0 && changed.length === 0 && errors.length === 0,
    leaks,
    changed,
    errors,
    allowed,
    removed,
  };
}
