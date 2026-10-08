/**
 * Run levels of the default project list, and what `bun run test:nightly` runs beyond them.
 *
 * `bun run test` is unit + api (`setup` comes in as a dependency). `bun run test:full` adds
 * `supported-apps`. The nightly run starts with the full scope and then adds the
 * `slow` project (always safe: PHP matrix, DinD provision) and the engine-wide projects below,
 * each only when its opt-in is present, because each one either needs the Engine host, changes
 * the Engine for every other consumer, or cannot be undone by the suite.
 */

/** Projects of the everyday run; `setup` is pulled in as a dependency of `api`. */
export const DEFAULT_PROJECTS: readonly string[] = ['unit', 'api'];
/** Projects of the full run: the everyday ones plus the application catalogue. */
export const FULL_PROJECTS: readonly string[] = ['unit', 'api', 'supported-apps'];

export const SUPPORTED_APPS_NOTICE =
  'supported-apps were not run; the full run is: bun run test:full';

/**
 * The full scope is selected by `--full`, and implied by `--nightly` and `--pr` (the PR run
 * narrows the apps itself through SUPPORTED_APPS).
 */
export function fullScope(flags: { full: boolean; nightly: boolean; pr: boolean }): boolean {
  return flags.full || flags.nightly || flags.pr;
}

/**
 * Arguments of the single Playwright invocation. An explicit `--project` in the passthrough
 * replaces the default list; the passthrough is always appended unchanged.
 */
export function playwrightArgs(passthrough: readonly string[], full: boolean): string[] {
  const explicit = passthrough.some((arg) => arg === '--project' || arg.startsWith('--project='));
  const projects = full ? FULL_PROJECTS : DEFAULT_PROJECTS;
  return [
    'test',
    ...(explicit ? [] : projects.map((project) => `--project=${project}`)),
    ...passthrough,
  ];
}

/** Should the closing line about the skipped catalogue be printed? */
export function shouldPrintSupportedAppsNotice(
  full: boolean,
  passthrough: readonly string[]
): boolean {
  return !full && !passthrough.some((arg) => arg === '--project' || arg.startsWith('--project='));
}

export interface NightlyProject {
  project: string;
  /** Env var that must be set for the project to run; `undefined` when it always runs. */
  optIn?: string;
  /** Must the variable equal `1` (a switch), or just be non-empty (a value the spec needs). */
  switch: boolean;
  why: string;
}

/** In run order: reversible first, the ones that restart or replace the Engine last. */
export const NIGHTLY_PROJECTS: readonly NightlyProject[] = [
  {
    project: 'slow',
    switch: false,
    why: 'full PHP-version matrix and DinD provision; only costs time, changes nothing global',
  },
  {
    project: 'cli',
    optIn: 'NIGHTLY_CLI',
    switch: true,
    why: 'needs pae-artisan on the host (SSH); the specs skip themselves without it',
  },
  {
    project: 'engine-cert',
    optIn: 'NIGHTLY_ENGINE_CERT',
    switch: true,
    why: 'the certificate challenge briefly takes hosted sites offline',
  },
  {
    project: 'network-mutation',
    optIn: 'ALLOW_NETWORK_MUTATION',
    switch: true,
    why: 'live network-config writes; the spec already requires this variable',
  },
  {
    project: 'webserver-change',
    optIn: 'WEBSERVER',
    switch: false,
    why: 'switches the Engine webserver to the named target; the spec already requires WEBSERVER; Engine v2.x refuses switches, so it only verifies the refusal until switching is re-enabled',
  },
  {
    project: 'update',
    optIn: 'NIGHTLY_UPDATE',
    switch: true,
    why: 'replaces the Engine software the rest of the suite tests; last by design',
  },
];

export interface NightlyDecision {
  project: string;
  run: boolean;
  reason: string;
}

export function nightlyPlan(env: NodeJS.ProcessEnv): NightlyDecision[] {
  return NIGHTLY_PROJECTS.map((entry) => {
    if (entry.optIn === undefined) {
      return { project: entry.project, run: true, reason: `always: ${entry.why}` };
    }
    const value = env[entry.optIn]?.trim() ?? '';
    const on = entry.switch ? value === '1' : value.length > 0;
    return on
      ? { project: entry.project, run: true, reason: `${entry.optIn} is set: ${entry.why}` }
      : { project: entry.project, run: false, reason: `set ${entry.optIn} to run: ${entry.why}` };
  });
}

export function describeNightly(plan: readonly NightlyDecision[]): string {
  return [
    'Nightly: the full suite, then',
    ...plan.map((entry) => `  ${entry.run ? 'run ' : 'skip'} ${entry.project} - ${entry.reason}`),
  ].join('\n');
}
