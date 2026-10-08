import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { expect, test } from '@/fixtures/test-options';
import {
  API_SUITE_IGNORE,
  SERIAL_SPECS,
  isSerialSpec,
  serialGlobToRegExp,
} from '@/config/serial-specs';

/** The split of the API suite into `api-parallel` and `api-serial`. */

const testsDir = path.resolve(process.cwd(), 'tests');

function specFiles(dir: string): string[] {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      return specFiles(full);
    }
    return entry.name.endsWith('.spec.ts') ? [full] : [];
  });
}

const allSpecs = specFiles(testsDir);
const apiSpecs = allSpecs.filter(
  (file) => !API_SUITE_IGNORE.some((ignored) => ignored.test(file.replace(/\\/g, '/')))
);

test.describe('serial spec list', () => {
  test('every entry names a reason and matches at least one spec file', () => {
    for (const entry of SERIAL_SPECS) {
      expect(entry.reason.length, `${entry.glob} has no reason`).toBeGreaterThan(10);
      const matcher = serialGlobToRegExp(entry.glob);
      const matched = allSpecs.filter((file) => matcher.test(file.replace(/\\/g, '/')));
      expect(matched.length, `${entry.glob} matches no spec file`).toBeGreaterThan(0);
    }
  });

  test('no entry is listed twice', () => {
    const globs = SERIAL_SPECS.map((entry) => entry.glob);
    expect(new Set(globs).size).toBe(globs.length);
  });

  test('the areas the plan names are serial', () => {
    const serial = (name: string): boolean => isSerialSpec(`tests/${name}.spec.ts`);
    for (const name of [
      'firewall/firewall',
      'firewall/firewall-logs',
      'modsec/modsec-waf-blocking',
      'exim/exim',
      'system/webserver',
      'system/ssl-config',
      'system/network-config',
      'system/ipv4-nat',
      'ip/ipam-subnets',
      'ip/ipam-assignments',
      'proxy-rules/proxy-rules',
      'cli/settings',
      'php/php-ini',
      'php/php-versions',
      'php/php-workers',
      'domains/domains-global',
      'lscache/lscache-purge',
      'integration/htaccess',
      'users/users-list',
    ]) {
      expect(serial(name), name).toBe(true);
    }
    for (const name of ['files/files-core', 'sftp/sftp', 'mysql/mysql-users', 'health/health']) {
      expect(serial(name), name).toBe(false);
    }
  });

  test('a glob star stays inside one path segment', () => {
    expect(
      serialGlobToRegExp('system/webserver*').test('/x/tests/system/webserver-change.spec.ts')
    ).toBe(true);
    expect(serialGlobToRegExp('firewall/*').test('/x/tests/firewall/deep/a.spec.ts')).toBe(false);
    expect(serialGlobToRegExp('php/php-ini').test('/x/tests/php/php-ini-extra.spec.ts')).toBe(
      false
    );
  });

  test('every API spec is in exactly one of the two projects', () => {
    const serial = apiSpecs.filter((file) => isSerialSpec(file));
    const parallel = apiSpecs.filter((file) => !isSerialSpec(file));
    expect(serial.length).toBeGreaterThan(0);
    expect(parallel.length).toBeGreaterThan(0);
    expect(new Set([...serial, ...parallel]).size).toBe(apiSpecs.length);
    expect(serial.length + parallel.length).toBe(apiSpecs.length);
  });
});

interface JsonSuite {
  file?: string;
  suites?: JsonSuite[];
  specs?: { title: string; file: string; tests: { projectName: string }[] }[];
}

/** `file › title` of every test per project, from `playwright test --list` with no Engine. */
function listTests(projects: string[], extraEnv: Record<string, string>): Map<string, Set<string>> {
  const env: NodeJS.ProcessEnv = { ...process.env };
  for (const key of Object.keys(env)) {
    if (key.startsWith('PW_') || key.startsWith('PLAYWRIGHT') || key === 'TEST_WORKER_INDEX') {
      delete env[key];
    }
  }
  Object.assign(env, {
    API_BASE_URL: '',
    API_TOKEN: '',
    DOMAIN: 'list.example.test',
    TEST_ENV: '',
    TEST_PARALLEL: '',
    SUPPORTED_APPS: '',
    JUNIT_REPORT_FILE: '',
    ...extraEnv,
  });
  const result = spawnSync(
    process.execPath,
    [
      path.resolve(process.cwd(), 'node_modules/@playwright/test/cli.js'),
      'test',
      '--list',
      '--reporter=json',
      ...projects.map((project) => `--project=${project}`),
    ],
    { cwd: process.cwd(), env, encoding: 'utf-8', maxBuffer: 256 * 1024 * 1024 }
  );
  const report = JSON.parse(result.stdout) as { suites: JsonSuite[] };
  const byProject = new Map<string, Set<string>>();
  const walk = (suite: JsonSuite): void => {
    for (const spec of suite.specs ?? []) {
      for (const entry of spec.tests) {
        const set = byProject.get(entry.projectName) ?? new Set<string>();
        set.add(`${spec.file} › ${spec.title}`);
        byProject.set(entry.projectName, set);
      }
    }
    suite.suites?.forEach(walk);
  };
  report.suites.forEach(walk);
  return byProject;
}

test.describe('--list equivalence', () => {
  test.describe.configure({ timeout: 120_000 });

  test('off lists the old api project (and its setup); split lists the same tests across two projects', () => {
    const off = listTests(['api'], {});
    expect([...off.keys()].sort()).toEqual(['api', 'setup']);
    const before = off.get('api') ?? new Set<string>();
    expect(before.size).toBeGreaterThan(100);

    const split = listTests(['api', 'api-parallel', 'api-serial'], {
      PW_API_WORKERS: '2',
      PW_APPS_WORKERS: '2',
    });
    const parallel = split.get('api-parallel') ?? new Set<string>();
    const serial = split.get('api-serial') ?? new Set<string>();
    expect(parallel.size).toBeGreaterThan(0);
    expect(serial.size).toBeGreaterThan(0);
    expect([...parallel].filter((id) => serial.has(id))).toEqual([]);
    expect(new Set([...parallel, ...serial])).toEqual(before);
    // The split leaves the unsplit project in place for --project=api runs.
    expect(split.get('api')).toEqual(before);
  });
});
