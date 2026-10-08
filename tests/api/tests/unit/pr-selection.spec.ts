import fs from 'node:fs';
import path from 'node:path';
import { expect, test } from '@/fixtures/test-options';
import { PR_RULES, PR_SHARED_RULES, PR_SUPPORTED_APPS } from '@/config/pr-selection';
import { SUPPORTED_APPS, selectedSupportedApps } from '@/test-data/static/supported-apps';
import {
  detectChangedFiles,
  findSmokeTests,
  listApiSpecs,
  pathGlobToRegExp,
  pickApps,
  planPrRun,
  selectTests,
  selectWithoutGit,
  type Git,
} from '../../scripts/lib/pr-selection';
import {
  SUPPORTED_APPS_NOTICE,
  fullScope,
  nightlyPlan,
  playwrightArgs,
  shouldPrintSupportedAppsNotice,
} from '../../scripts/lib/run-levels';

/** Which tests a pull request runs: the path map, its fallbacks and the git detection. */

const suiteRoot = process.cwd();
const apiSpecs = listApiSpecs(suiteRoot);
const PREFIX = 'tests/api/';
const SMOKE = [{ file: 'tests/health/health.spec.ts', line: 4 }];

function select(changed: string[], env: NodeJS.ProcessEnv = {}) {
  return selectTests({ changed, suitePrefix: PREFIX, apiSpecs, smoke: SMOKE, env });
}

test.describe('path to spec mapping', () => {
  test('a domain controller change selects the domains specs and nothing global besides them', () => {
    const selection = select(['core/app/Http/Controllers/User/DomainController.php']);
    expect(selection.mode).toBe('selected');
    expect(selection.specs).toContain('tests/domains/domains-validation.spec.ts');
    expect(selection.specs).toContain('tests/integration/www-redirect.spec.ts');
    expect(selection.specs.some((spec) => spec.startsWith('tests/modsec/'))).toBe(false);
    expect(selection.specs.some((spec) => spec.startsWith('tests/mysql/'))).toBe(false);
  });

  test('ModSecurity code selects the modsec specs, which run in the serial phase', () => {
    const selection = select(['core/app/Lib/Modsec/RuleSet.php', 'core/app/Foo/ModSecThing.php']);
    expect(selection.mode).toBe('selected');
    expect(selection.specs.every((spec) => spec.startsWith('tests/modsec/'))).toBe(true);
    expect(selection.specs.length).toBeGreaterThan(0);
    expect(selection.serialSpecs).toEqual(selection.specs);
  });

  test('a changed spec runs itself; a helper beside specs runs its directory', () => {
    const own = select([`${PREFIX}tests/cron/cron.spec.ts`]);
    expect(own.specs).toEqual(['tests/cron/cron.spec.ts']);
    const dir = select([`${PREFIX}tests/files/some-fixture.txt`]);
    expect(dir.specs.length).toBe(
      apiSpecs.filter((spec) => spec.startsWith('tests/files/')).length
    );
  });

  test('files that need no engine run select nothing but smoke and the apps', () => {
    const selection = select([
      'README.md',
      `${PREFIX}scripts/run-tests.ts`,
      'docs/x/y.html',
      'tests/unit/Unit/Testing/CoverageRecorderTest.php',
    ]);
    expect(selection.mode).toBe('selected');
    expect(selection.specs).toEqual([]);
    expect(selection.smoke).toEqual(SMOKE);
    expect(selection.checks).toBe(false);
  });

  test('every spec of the api project is reachable from some rule', () => {
    const reachable = new Set<string>();
    for (const rule of [...PR_RULES, ...PR_SHARED_RULES]) {
      for (const glob of rule.specs ?? []) {
        const body = glob.replace(/\*/g, '[^/]*');
        const matcher = new RegExp(`^tests/${body}\\.spec\\.ts$`);
        apiSpecs.filter((spec) => matcher.test(spec)).forEach((spec) => reachable.add(spec));
      }
    }
    const missing = apiSpecs.filter((spec) => !reachable.has(spec));
    expect(missing, 'specs no PR rule can ever select').toEqual([]);
  });

  test('every rule spec glob matches at least one spec or names a project outside the api run', () => {
    for (const rule of PR_RULES) {
      for (const glob of rule.specs ?? []) {
        const matcher = new RegExp(`^tests/${glob.replace(/\*/g, '[^/]*')}\\.spec\\.ts$`);
        const hit = apiSpecs.some((spec) => matcher.test(spec));
        expect(hit || glob.startsWith('cli/'), `${rule.id}: ${glob} matches no spec`).toBe(true);
      }
    }
  });

  test('path globs: ** crosses directories, * does not', () => {
    expect(pathGlobToRegExp('core/app/**').test('core/app/Lib/Ssl/A.php')).toBe(true);
    expect(pathGlobToRegExp('core/app/*').test('core/app/Lib/Ssl/A.php')).toBe(false);
    expect(pathGlobToRegExp('**/*.md').test('README.md')).toBe(true);
    expect(pathGlobToRegExp('**/*.md').test('a/b/c.md')).toBe(true);
  });
});

test.describe('fallback to the full API project', () => {
  for (const file of [
    'core/app/Support/EnvFile.php',
    'core/config/system.php',
    'core/composer.lock',
    'dockerfiles/Dockerfile-core',
    'templates/nginx/site.conf',
    `${PREFIX}helpers/retry.ts`,
    `${PREFIX}playwright.config.ts`,
  ]) {
    test(`${file} runs everything`, () => {
      const selection = select([file]);
      expect(selection.mode).toBe('full');
      expect(selection.specs).toEqual(apiSpecs);
      expect(selection.checks).toBe(true);
      expect(selection.filters).toContain('tests/deploy/supported-apps-checks.spec.ts');
    });
  }

  test('an unknown engine path falls back and says which', () => {
    const selection = select([
      'core/app/Brand/NewThing.php',
      'core/app/Http/Controllers/User/CronJobController.php',
    ]);
    expect(selection.mode).toBe('full');
    expect(selection.fullReason).toContain('core/app/Brand/NewThing.php');
    expect(selection.groups.some((group) => group.id === 'unmapped')).toBe(true);
  });

  test('git being unavailable falls back and says so', () => {
    const selection = selectWithoutGit('git is unavailable', apiSpecs, {});
    expect(selection.mode).toBe('full');
    expect(selection.fullReason).toContain('git is unavailable');
    expect(selection.specs).toEqual(apiSpecs);
  });

  test('planPrRun falls back when the git binary fails', () => {
    const broken: Git = () => {
      throw new Error('spawn git ENOENT');
    };
    const selection = planPrRun(suiteRoot, {}, broken);
    expect(selection.mode).toBe('full');
    expect(selection.fullReason).toContain('git is unavailable');
  });
});

test.describe('union of smoke and mapped specs', () => {
  test('a smoke test in a selected file is not listed twice', () => {
    const selection = select(['core/app/Http/Controllers/ServerMetricsController.php']);
    // health/* is selected as a whole, so the smoke line of health.spec.ts adds nothing.
    expect(selection.specs).toContain('tests/health/health.spec.ts');
    expect(selection.smoke).toEqual([]);
    expect(new Set(selection.filters).size).toBe(selection.filters.length);
  });

  test('two groups naming the same spec select it once', () => {
    const selection = select([
      'core/app/Http/Controllers/User/DomainController.php',
      'core/app/Lib/Domains/DomainAllocator.php',
      'core/app/System/Services/Webserver.php',
    ]);
    expect(new Set(selection.specs).size).toBe(selection.specs.length);
    expect(selection.specs.filter((spec) => spec.endsWith('www-redirect.spec.ts'))).toHaveLength(1);
  });

  test('smoke tests of unselected files are added as file:line, with the setup spec first', () => {
    const selection = select(['core/app/Http/Controllers/User/CronJobController.php']);
    expect(selection.smoke).toEqual(SMOKE);
    expect(selection.filters[0]).toBe('tests/setup/test-user.setup.ts');
    expect(selection.filters).toContain('tests/cron/cron.spec.ts');
    expect(selection.filters).toContain('tests/health/health.spec.ts:4');
  });

  test('smoke discovery points at the test call that carries the tag', () => {
    const source = [
      "test.describe('x', () => {",
      '  test(',
      "    'a title',",
      "    { tag: ['@smoke'] },",
      '    async () => {}',
      '  );',
      "  test('b', { tag: ['@smoke'] }, async () => {});",
      '  // @smoke in a comment',
      '});',
    ].join('\n');
    expect(findSmokeTests(['f.spec.ts'], () => source)).toEqual([
      { file: 'f.spec.ts', line: 2 },
      { file: 'f.spec.ts', line: 7 },
    ]);
  });

  test('the real suite has smoke tests and each maps to a line holding a test call', () => {
    const smoke = findSmokeTests(apiSpecs, (file) =>
      fs.readFileSync(path.join(suiteRoot, file), 'utf-8')
    );
    expect(smoke.length).toBeGreaterThanOrEqual(8);
  });
});

test.describe('supported apps for a PR', () => {
  test('the defaults exist in the catalogue and come from different build families', () => {
    const picked = selectedSupportedApps(PR_SUPPORTED_APPS.join(','));
    expect(picked.map((entry) => entry.slug).sort()).toEqual([...PR_SUPPORTED_APPS].sort());
    const stacks = new Set(picked.map((entry) => entry.stack));
    expect(stacks.size).toBe(PR_SUPPORTED_APPS.length);
    expect(SUPPORTED_APPS.length).toBeGreaterThan(PR_SUPPORTED_APPS.length);
  });

  test('the catalogue runs with the apps; the checks file only when deploy-related code changed', () => {
    const plain = select(['core/app/Http/Controllers/User/CronJobController.php']);
    expect(plain.apps).toEqual([...PR_SUPPORTED_APPS]);
    expect(plain.filters).toContain('tests/deploy/supported-apps.spec.ts');
    expect(plain.filters).not.toContain('tests/deploy/supported-apps-checks.spec.ts');

    const staging = select(['core/app/Http/Controllers/User/StagingController.php']);
    expect(staging.filters).toContain('tests/deploy/supported-apps-checks.spec.ts');
  });

  test('PR_APPS replaces the apps and can turn them off', () => {
    expect(pickApps({ PR_APPS: 'koel, Memos' }).apps).toEqual(['koel', 'memos']);
    const off = select(['core/app/Http/Controllers/User/CronJobController.php'], { PR_APPS: '0' });
    expect(off.apps).toEqual([]);
    expect(off.filters).not.toContain('tests/deploy/supported-apps.spec.ts');
  });
});

test.describe('changed files from git', () => {
  function fakeGit(answers: Record<string, string | Error>): Git {
    return (args) => {
      const answer = answers[args.join(' ')];
      if (answer === undefined || answer instanceof Error) {
        throw answer ?? new Error(`unexpected git ${args.join(' ')}`);
      }
      return answer;
    };
  }

  test('diffs against the merge base and adds untracked files', () => {
    const result = detectChangedFiles(
      fakeGit({
        'rev-parse --git-dir': '.git',
        'rev-parse --verify --quiet origin/main^{commit}': 'abc',
        'merge-base HEAD origin/main': 'deadbeef00000\n',
        'diff --name-only deadbeef00000': 'core/a.php\ncore/b.php\n',
        'ls-files --others --exclude-standard --full-name': 'core/new.php\ncore/a.php\n',
      }),
      {}
    );
    expect(result).toMatchObject({ ok: true });
    expect(result.ok && result.files).toEqual(['core/a.php', 'core/b.php', 'core/new.php']);
  });

  test('PR_BASE wins and an unknown PR_BASE is a fallback reason', () => {
    const git = fakeGit({
      'rev-parse --git-dir': '.git',
      'rev-parse --verify --quiet release^{commit}': 'abc',
      'merge-base HEAD release': 'cafe\n',
      'diff --name-only cafe': '',
      'ls-files --others --exclude-standard --full-name': '',
    });
    expect(detectChangedFiles(git, { PR_BASE: 'release' })).toMatchObject({ ok: true });
    const missing = detectChangedFiles(git, { PR_BASE: 'nope' });
    expect(missing).toMatchObject({ ok: false });
  });

  test('no base branch and no git are reasons, not exceptions', () => {
    expect(
      detectChangedFiles(fakeGit({ 'rev-parse --git-dir': new Error('fatal') }), {})
    ).toMatchObject({ ok: false, reason: expect.stringContaining('git is unavailable') });
    const noBase = detectChangedFiles(fakeGit({ 'rev-parse --git-dir': '.git' }), {});
    expect(noBase).toMatchObject({ ok: false, reason: expect.stringContaining('no base branch') });
  });
});

test.describe('nightly levels', () => {
  test('only slow runs by default; opt-ins add the engine-wide projects', () => {
    const run = (env: NodeJS.ProcessEnv) =>
      nightlyPlan(env)
        .filter((entry) => entry.run)
        .map((entry) => entry.project);
    expect(run({})).toEqual(['slow']);
    expect(run({ NIGHTLY_CLI: '1', ALLOW_NETWORK_MUTATION: '1', WEBSERVER: 'nginx' })).toEqual([
      'slow',
      'cli',
      'network-mutation',
      'webserver-change',
    ]);
    expect(run({ NIGHTLY_UPDATE: '0' })).toEqual(['slow']);
  });
});

test.describe('run levels', () => {
  test('the default run is unit + api, --full adds supported-apps', () => {
    expect(playwrightArgs([], false)).toEqual(['test', '--project=unit', '--project=api']);
    expect(playwrightArgs([], true)).toEqual([
      'test',
      '--project=unit',
      '--project=api',
      '--project=supported-apps',
    ]);
    expect(playwrightArgs(['tests/users'], false)).toEqual([
      'test',
      '--project=unit',
      '--project=api',
      'tests/users',
    ]);
  });

  test('an explicit --project passes through untouched at both levels', () => {
    for (const full of [false, true]) {
      expect(playwrightArgs(['--project=slow'], full)).toEqual(['test', '--project=slow']);
      expect(playwrightArgs(['--project', 'cli'], full)).toEqual(['test', '--project', 'cli']);
    }
  });

  test('--full, --nightly and --pr select the full scope; nothing else does', () => {
    const flags = { full: false, nightly: false, pr: false };
    expect(fullScope(flags)).toBe(false);
    expect(fullScope({ ...flags, full: true })).toBe(true);
    expect(fullScope({ ...flags, nightly: true })).toBe(true);
    expect(fullScope({ ...flags, pr: true })).toBe(true);
  });

  test('the supported-apps notice is for the default run only', () => {
    expect(SUPPORTED_APPS_NOTICE).toBe(
      'supported-apps were not run; the full run is: bun run test:full'
    );
    expect(shouldPrintSupportedAppsNotice(false, [])).toBe(true);
    expect(shouldPrintSupportedAppsNotice(false, ['tests/users'])).toBe(true);
    expect(shouldPrintSupportedAppsNotice(true, [])).toBe(false);
    expect(shouldPrintSupportedAppsNotice(false, ['--project=slow'])).toBe(false);
  });
});
