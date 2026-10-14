import type { TestResult } from '@playwright/test/reporter';
import {
  EXPECTED_SKIPS,
  SKIP_CATEGORIES,
  type ExpectedSkip,
  type SkipCategory,
} from '../config/expected-skips';

export interface SkipEntry {
  title: string;
  reason: string;
}

export interface ExpectedGroup {
  rule: ExpectedSkip;
  entries: SkipEntry[];
}

export interface SkipClassification {
  total: number;
  expected: ExpectedGroup[];
  unexpected: SkipEntry[];
  /** Expected reasons seen more often than their `max`. */
  overMax: ExpectedGroup[];
  byCategory: Record<SkipCategory, number>;
}

/** `skipTest()` prefixes its reason with `[skip] `; the rules are written without it. */
function normalise(reason: string): string {
  return reason.replace(/^\[skip\]\s*/, '').trim();
}

export function findRule(
  reason: string,
  rules: readonly ExpectedSkip[] = EXPECTED_SKIPS
): ExpectedSkip | undefined {
  const text = normalise(reason);
  return rules.find((rule) => rule.match.test(text));
}

export function classifySkips(
  entries: readonly SkipEntry[],
  rules: readonly ExpectedSkip[] = EXPECTED_SKIPS
): SkipClassification {
  const groups = new Map<string, ExpectedGroup>();
  const unexpected: SkipEntry[] = [];
  for (const entry of entries) {
    const rule = findRule(entry.reason, rules);
    if (!rule) {
      unexpected.push(entry);
      continue;
    }
    const group = groups.get(rule.id) ?? { rule, entries: [] };
    group.entries.push(entry);
    groups.set(rule.id, group);
  }

  const expected = [...groups.values()].sort((a, b) => b.entries.length - a.entries.length);
  const byCategory = Object.fromEntries(SKIP_CATEGORIES.map((category) => [category, 0])) as Record<
    SkipCategory,
    number
  >;
  for (const group of expected) {
    byCategory[group.rule.category] += group.entries.length;
  }
  return {
    total: entries.length,
    expected,
    unexpected,
    overMax: expected.filter(
      (group) => group.rule.max !== undefined && group.entries.length > group.rule.max
    ),
    byCategory,
  };
}

/** Playwright records a skip reason as an annotation, or leaves none for `test.skip()`. */
export function reasonOf(result: TestResult): string {
  const annotation = result.annotations?.find((entry) => entry.type === 'skip');
  return annotation?.description?.trim() ?? 'no reason given';
}
