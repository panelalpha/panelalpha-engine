import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

import {
  CATALOGUE_SPEC,
  CHECKS_SPEC,
  PR_IGNORED_PATHS,
  PR_RULES,
  PR_SHARED_RULES,
  PR_SUPPORTED_APPS,
  SETUP_SPEC,
  type PrRule,
} from '@/config/pr-selection';
import { API_SUITE_IGNORE, isSerialSpec, serialGlobToRegExp } from '@/config/serial-specs';

/**
 * The selection behind `bun run test:pr`: changed paths in, a list of Playwright file filters out.
 * Everything here is pure except `detectChangedFiles` and the two file readers, which take their
 * I/O as arguments so the unit tests can run without git.
 */

export interface SmokeTest {
  file: string;
  line: number;
}

export interface SelectionGroup {
  id: string;
  reason: string;
  /** Changed paths that triggered the group. */
  changed: string[];
  /** Spec files the group adds (API project only). */
  specs: string[];
}

export interface PrSelection {
  mode: 'selected' | 'full';
  fullReason?: string;
  base?: string;
  changedFiles: string[];
  groups: SelectionGroup[];
  /** Whole spec files, each once. */
  specs: string[];
  /** `@smoke` tests from files that are not selected as a whole. */
  smoke: SmokeTest[];
  /** The subset of `specs` that config/serial-specs.ts runs alone. */
  serialSpecs: string[];
  apps: string[];
  catalogue: boolean;
  checks: boolean;
  /** Arguments for `playwright test`: files, `file:line` for smoke tests. */
  filters: string[];
  notes: string[];
}

export type ChangedFiles =
  { ok: true; base: string; files: string[] } | { ok: false; reason: string };

export type Git = (args: string[]) => string;

/** `**` crosses segments, `*` does not. Anchored at both ends. */
export function pathGlobToRegExp(glob: string): RegExp {
  let source = '';
  for (let i = 0; i < glob.length; i += 1) {
    const char = glob[i];
    if (char === '*' && glob[i + 1] === '*') {
      if (glob[i + 2] === '/') {
        source += '(?:.*/)?';
        i += 2;
      } else {
        source += '.*';
        i += 1;
      }
    } else if (char === '*') {
      source += '[^/]*';
    } else {
      source += char.replace(/[.+?^${}()|[\]\\]/g, '\\$&');
    }
  }
  return new RegExp(`^${source}$`);
}

function expandSuite(pattern: string, suitePrefix: string): string {
  return pattern.startsWith('@suite/')
    ? `${suitePrefix}${pattern.slice('@suite/'.length)}`
    : pattern;
}

function matchesAny(file: string, patterns: readonly string[], suitePrefix: string): boolean {
  return patterns.some((pattern) => pathGlobToRegExp(expandSuite(pattern, suitePrefix)).test(file));
}

/** Spec files the `api` project runs, relative to the suite root, with forward slashes. */
export function listApiSpecs(suiteRoot: string): string[] {
  const out: string[] = [];
  const walk = (dir: string): void => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        walk(full);
      } else if (entry.name.endsWith('.spec.ts')) {
        out.push(path.relative(suiteRoot, full).split(path.sep).join('/'));
      }
    }
  };
  walk(path.join(suiteRoot, 'tests'));
  return out.filter((file) => !API_SUITE_IGNORE.some((ignored) => ignored.test(file))).sort();
}

/**
 * Every `@smoke` tag, as the line of the `test(` call it belongs to, so `file:line` selects that
 * one test. A tag on a `describe` selects the whole block the same way.
 */
export function findSmokeTests(
  files: readonly string[],
  readFile: (file: string) => string
): SmokeTest[] {
  const found: SmokeTest[] = [];
  for (const file of files) {
    const lines = readFile(file).split('\n');
    lines.forEach((text, index) => {
      if (!text.includes('@smoke') || /^\s*(\/\/|\*|\/\*)/.test(text)) {
        return;
      }
      for (let at = index; at >= 0; at -= 1) {
        if (/\btest(?:\.describe)?(?:\.\w+)?\s*\(/.test(lines[at])) {
          if (!found.some((entry) => entry.file === file && entry.line === at + 1)) {
            found.push({ file, line: at + 1 });
          }
          return;
        }
      }
    });
  }
  return found;
}

/**
 * Changed files against the merge base with the base branch, working tree included
 * (so uncommitted work is selected too). Paths are relative to the repository root.
 * `PR_BASE` names the base; otherwise the first of origin/main, main, origin/master, master
 * that exists. Anything that goes wrong is a reason string, never a throw.
 */
export function detectChangedFiles(git: Git, env: NodeJS.ProcessEnv): ChangedFiles {
  const explicit = env.PR_BASE?.trim();
  const candidates = explicit ? [explicit] : ['origin/main', 'main', 'origin/master', 'master'];
  try {
    git(['rev-parse', '--git-dir']);
  } catch {
    return { ok: false, reason: 'git is unavailable or this is not a git checkout' };
  }
  let base: string | undefined;
  let mergeBase: string | undefined;
  for (const candidate of candidates) {
    try {
      git(['rev-parse', '--verify', '--quiet', `${candidate}^{commit}`]);
      mergeBase = git(['merge-base', 'HEAD', candidate]).trim();
      base = candidate;
      break;
    } catch {
      // Try the next candidate.
    }
  }
  if (base === undefined || !mergeBase) {
    return {
      ok: false,
      reason: explicit
        ? `PR_BASE=${explicit} is not a known revision`
        : `no base branch found (tried ${candidates.join(', ')}); set PR_BASE`,
    };
  }
  try {
    const committed = git(['diff', '--name-only', mergeBase]).split('\n');
    const untracked = git(['ls-files', '--others', '--exclude-standard', '--full-name']).split(
      '\n'
    );
    const files = [...new Set([...committed, ...untracked].map((f) => f.trim()).filter(Boolean))];
    return { ok: true, base: `${base} (${mergeBase.slice(0, 10)})`, files: files.sort() };
  } catch (error) {
    return {
      ok: false,
      reason: `git diff failed: ${error instanceof Error ? error.message.split('\n')[0] : 'unknown error'}`,
    };
  }
}

/** A `git` runner rooted at the suite; `undefined` is never returned, a missing binary throws. */
export function gitRunner(cwd: string): Git {
  return (args) =>
    execFileSync('git', args, { cwd, encoding: 'utf-8', stdio: ['ignore', 'pipe', 'pipe'] });
}

export function suitePrefixOf(git: Git): string {
  try {
    return git(['rev-parse', '--show-prefix']).trim();
  } catch {
    return '';
  }
}

export interface SelectInput {
  changed: readonly string[];
  suitePrefix: string;
  /** Suite-relative paths of every spec in the `api` project. */
  apiSpecs: readonly string[];
  smoke: readonly SmokeTest[];
  env?: NodeJS.ProcessEnv;
  base?: string;
}

function specsFor(globs: readonly string[], apiSpecs: readonly string[]): string[] {
  const matchers = globs.map((glob) => serialGlobToRegExp(glob));
  return apiSpecs.filter((spec) => matchers.some((matcher) => matcher.test(spec)));
}

/** Slugs for the Supported-apps catalogue: `PR_APPS`, else the defaults; `0`/`off` means none. */
export function pickApps(env: NodeJS.ProcessEnv): { apps: string[]; note?: string } {
  const raw = env.PR_APPS?.trim();
  if (raw === undefined || raw === '') {
    return { apps: [...PR_SUPPORTED_APPS] };
  }
  if (['0', 'off', 'false', 'none'].includes(raw.toLowerCase())) {
    return { apps: [], note: 'PR_APPS is off: no supported app runs.' };
  }
  return {
    apps: raw
      .split(',')
      .map((slug) => slug.trim().toLowerCase())
      .filter(Boolean),
    note: `PR_APPS=${raw} replaces the default apps.`,
  };
}

/** The API suite with its setup, plus the catalogue and the checks: the fallback scope. */
function fullSelection(input: SelectInput, reason: string, groups: SelectionGroup[]): PrSelection {
  const { apps, note } = pickApps(input.env ?? {});
  const specs = [...input.apiSpecs];
  return finish({
    mode: 'full',
    fullReason: reason,
    base: input.base,
    changedFiles: [...input.changed],
    groups,
    specs,
    smoke: [],
    apps,
    catalogue: apps.length > 0,
    checks: true,
    notes: note ? [note] : [],
  });
}

function finish(
  partial: Omit<PrSelection, 'filters' | 'serialSpecs'> & Partial<PrSelection>
): PrSelection {
  const specs = [...new Set(partial.specs)].sort();
  const smoke = partial.smoke.filter((entry) => !specs.includes(entry.file));
  const runsApi = specs.length > 0 || smoke.length > 0;
  const filters = [
    ...(runsApi ? [SETUP_SPEC] : []),
    ...specs,
    ...smoke.map((entry) => `${entry.file}:${entry.line}`),
    ...(partial.catalogue ? [CATALOGUE_SPEC] : []),
    ...(partial.checks ? [CHECKS_SPEC] : []),
  ];
  return {
    ...partial,
    specs,
    smoke,
    serialSpecs: specs.filter((spec) => isSerialSpec(spec)),
    filters,
  };
}

/** Rule that names the file, or the dynamic rule for a spec or helper under `tests/<dir>/`. */
function ruleFor(file: string, rule: PrRule, suitePrefix: string): boolean {
  return matchesAny(file, rule.paths, suitePrefix);
}

export function selectTests(input: SelectInput): PrSelection {
  const env = input.env ?? {};
  const { suitePrefix, apiSpecs } = input;
  const groups = new Map<string, SelectionGroup>();
  const fullTriggers: SelectionGroup[] = [];
  const unmapped: string[] = [];
  let checks = false;

  const group = (rule: { id: string; reason: string }): SelectionGroup => {
    let entry = groups.get(rule.id);
    if (!entry) {
      entry = { id: rule.id, reason: rule.reason, changed: [], specs: [] };
      groups.set(rule.id, entry);
    }
    return entry;
  };

  for (const file of input.changed) {
    if (matchesAny(file, PR_IGNORED_PATHS, suitePrefix)) {
      group({
        id: 'ignored',
        reason: 'docs, tooling and unit-covered files: no engine run',
      }).changed.push(file);
      continue;
    }

    let matched = false;
    for (const rule of [...PR_SHARED_RULES, ...PR_RULES]) {
      if (!ruleFor(file, rule, suitePrefix)) {
        continue;
      }
      matched = true;
      const entry = group(rule);
      entry.changed.push(file);
      if (rule.full) {
        fullTriggers.push(entry);
      }
      if (rule.checks) {
        checks = true;
      }
      for (const spec of specsFor(rule.specs ?? [], apiSpecs)) {
        if (!entry.specs.includes(spec)) {
          entry.specs.push(spec);
        }
      }
    }

    // tests/<dir>/...: a changed spec runs itself, a helper next to specs runs its directory.
    const relative = file.startsWith(suitePrefix) ? file.slice(suitePrefix.length) : undefined;
    const dynamic = relative?.match(/^tests\/([^/]+)\//);
    if (relative && dynamic && dynamic[1] !== 'unit' && dynamic[1] !== 'setup') {
      const own = relative.endsWith('.spec.ts')
        ? apiSpecs.filter((spec) => spec === relative)
        : apiSpecs.filter((spec) => spec.startsWith(`tests/${dynamic[1]}/`));
      {
        matched = true;
        const entry = group({
          id: `suite-${dynamic[1]}`,
          reason: relative.endsWith('.spec.ts')
            ? 'the changed spec'
            : `helper data in tests/${dynamic[1]}/: every spec of the directory`,
        });
        entry.changed.push(file);
        entry.specs.push(...own.filter((spec) => !entry.specs.includes(spec)));
      }
    }

    if (!matched) {
      unmapped.push(file);
    }
  }

  const base = input.base;
  if (fullTriggers.length > 0) {
    return fullSelection(
      input,
      `shared code changed (${[...new Set(fullTriggers.map((entry) => entry.id))].join(', ')})`,
      [...groups.values()]
    );
  }
  if (unmapped.length > 0) {
    const shown = unmapped.slice(0, 3).join(', ');
    const more = unmapped.length > 3 ? ` and ${unmapped.length - 3} more` : '';
    const entry: SelectionGroup = {
      id: 'unmapped',
      reason: 'no rule in config/pr-selection.ts covers these paths',
      changed: unmapped,
      specs: [],
    };
    return fullSelection(input, `unmapped path(s): ${shown}${more}`, [...groups.values(), entry]);
  }

  const { apps, note } = pickApps(env);
  const selected = [...groups.values()].flatMap((entry) => entry.specs);
  const notes: string[] = note ? [note] : [];
  if (input.changed.length === 0) {
    notes.push('No changed files found: smoke tests and the PR apps only.');
  }
  return finish({
    mode: 'selected',
    base,
    changedFiles: [...input.changed],
    groups: [...groups.values()],
    specs: selected,
    smoke: [...input.smoke],
    apps,
    catalogue: apps.length > 0,
    checks,
    notes,
  });
}

/** Selection when git cannot say what changed: the full API project and all checks. */
export function selectWithoutGit(
  reason: string,
  apiSpecs: readonly string[],
  env: NodeJS.ProcessEnv
): PrSelection {
  return fullSelection(
    { changed: [], suitePrefix: '', apiSpecs, smoke: [], env },
    `${reason}; falling back to the full API project`,
    []
  );
}

export function describeSelection(selection: PrSelection): string {
  const lines: string[] = [];
  lines.push(
    selection.mode === 'full'
      ? `PR selection: FULL API project - ${selection.fullReason}`
      : 'PR selection: changed areas + smoke'
  );
  if (selection.base) {
    lines.push(`  base: ${selection.base}; ${selection.changedFiles.length} changed file(s)`);
  }
  for (const entry of selection.groups) {
    const shown = entry.changed.slice(0, 3).join(', ');
    const more = entry.changed.length > 3 ? ` (+${entry.changed.length - 3})` : '';
    lines.push(`  [${entry.id}] ${entry.reason}`);
    lines.push(`      from: ${shown}${more}`);
    if (entry.specs.length > 0) {
      lines.push(`      specs: ${entry.specs.length} file(s)`);
    }
  }
  if (selection.mode === 'selected') {
    lines.push(`  API specs: ${selection.specs.length} file(s)`);
    for (const spec of selection.specs) {
      lines.push(`    ${spec}${isSerialSpec(spec) ? '  (serial)' : ''}`);
    }
    lines.push(
      `  @smoke elsewhere: ${selection.smoke.length} test(s)` +
        (selection.smoke.length > 0
          ? ` - ${selection.smoke.map((s) => `${s.file}:${s.line}`).join(', ')}`
          : '')
    );
  } else {
    lines.push(`  API specs: ${selection.specs.length} file(s) (all)`);
  }
  lines.push(
    `  supported apps: ${selection.apps.length > 0 ? selection.apps.join(', ') : 'none'}`,
    `  supported-apps checks: ${selection.checks ? 'yes' : 'no'}`
  );
  if (selection.serialSpecs.length > 0) {
    lines.push(`  serial (exclusive) specs: ${selection.serialSpecs.length}`);
  }
  for (const note of selection.notes) {
    lines.push(`  note: ${note}`);
  }
  return lines.join('\n');
}

export function writeSelectionFile(root: string, selection: PrSelection): string {
  const file = path.join(root, '.playwright', 'pr-selection.json');
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, `${JSON.stringify(selection, null, 2)}\n`, 'utf-8');
  return file;
}

/** The whole selection for a checkout: git, the file system, the map. Falls back, never throws. */
export function planPrRun(suiteRoot: string, env: NodeJS.ProcessEnv, git?: Git): PrSelection {
  const run = git ?? gitRunner(suiteRoot);
  const apiSpecs = listApiSpecs(suiteRoot);
  const changed = detectChangedFiles(run, env);
  if (!changed.ok) {
    return selectWithoutGit(changed.reason, apiSpecs, env);
  }
  const smokeFiles = apiSpecs;
  const smoke = findSmokeTests(smokeFiles, (file) =>
    fs.readFileSync(path.join(suiteRoot, file), 'utf-8')
  );
  return selectTests({
    changed: changed.files,
    suitePrefix: suitePrefixOf(run),
    apiSpecs,
    smoke,
    env,
    base: changed.base,
  });
}
