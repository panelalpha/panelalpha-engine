import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { expect, test } from '@/fixtures/test-options';

/**
 * Every spec under tests/ has to be selected by at least one Playwright
 * project. A spec the api suite ignores and no other project matches never
 * runs, however green it would have been.
 */

const testsDir = path.resolve(process.cwd(), 'tests');

function specFiles(dir: string): string[] {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      return specFiles(full);
    }
    return entry.name.endsWith('.spec.ts')
      ? [path.relative(testsDir, full).replace(/\\/g, '/')]
      : [];
  });
}

interface JsonSuite {
  suites?: JsonSuite[];
  specs?: { file: string }[];
}

/**
 * Spec files every project matches, from `playwright test --list` with no
 * Engine. Paths are relative to the config's rootDir, which is tests/.
 *
 * The child writes to a file rather than a pipe: the list is large and Bun's
 * spawnSync returns a truncated pipe for it, which is unparseable.
 */
function listedSpecFiles(): Set<string> {
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
  });
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'playwright-list-'));
  const out = path.join(dir, 'list.json');
  const fd = fs.openSync(out, 'w');
  try {
    spawnSync(
      process.execPath,
      [
        path.resolve(process.cwd(), 'node_modules/@playwright/test/cli.js'),
        'test',
        '--list',
        '--reporter=json',
      ],
      { cwd: process.cwd(), env, stdio: ['ignore', fd, 'pipe'], encoding: 'utf-8' }
    );
  } finally {
    fs.closeSync(fd);
  }
  const report = JSON.parse(fs.readFileSync(out, 'utf-8')) as { suites: JsonSuite[] };
  fs.rmSync(dir, { recursive: true, force: true });

  const files = new Set<string>();
  const walk = (suite: JsonSuite): void => {
    for (const spec of suite.specs ?? []) {
      if (spec.file.endsWith('.spec.ts')) {
        files.add(spec.file.replace(/\\/g, '/'));
      }
    }
    suite.suites?.forEach(walk);
  };
  report.suites.forEach(walk);
  return files;
}

test.describe('spec selection', () => {
  test.describe.configure({ timeout: 180_000 });

  test('every spec file is selected by at least one project', () => {
    const onDisk = specFiles(testsDir);
    const listed = listedSpecFiles();
    const orphaned = onDisk.filter((file) => !listed.has(file)).sort();

    expect(orphaned, 'specs no Playwright project selects').toEqual([]);
  });
});
