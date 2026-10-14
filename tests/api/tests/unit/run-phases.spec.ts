import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { expect, test } from '@/fixtures/test-options';
import {
  combinedExitCode,
  countJunitSkipped,
  fetchEngineRamBytes,
  hasProjectArg,
  phaseArgs,
  phaseJunitFile,
  ramBytesFromMetrics,
  skipBudgetFailure,
} from '../../scripts/lib/phases';

/** The pure parts of the split run in scripts/run-tests.ts. */

function junit(contents: string): string {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'phases-'));
  const file = path.join(dir, 'junit.xml');
  fs.writeFileSync(file, contents, 'utf-8');
  return file;
}

test.describe('phase arguments', () => {
  const parallelism = { apiWorkers: 3 };

  test('the parallel phase of the full scope runs unit, setup, api-parallel and supported-apps', () => {
    expect(
      phaseArgs({ phase: 'parallel', parallelism, passthrough: ['--grep', '@smoke'], full: true })
    ).toEqual([
      'test',
      '--project=unit',
      '--project=setup',
      '--project=api-parallel',
      '--project=supported-apps',
      '--workers=3',
      '--pass-with-no-tests',
      '--grep',
      '@smoke',
    ]);
  });

  test('the parallel phase without the full scope leaves supported-apps out', () => {
    const args = phaseArgs({ phase: 'parallel', parallelism, passthrough: [] });
    expect(args).toEqual([
      'test',
      '--project=unit',
      '--project=setup',
      '--project=api-parallel',
      '--workers=3',
      '--pass-with-no-tests',
    ]);
  });

  test('the serial phase is api-serial alone, without setup, on one worker whatever is passed', () => {
    const args = phaseArgs({
      phase: 'serial',
      parallelism,
      passthrough: ['--workers=4', 'tests/ip'],
    });
    expect(args.slice(0, 4)).toEqual([
      'test',
      '--project=api-serial',
      '--no-deps',
      '--pass-with-no-tests',
    ]);
    expect(args).not.toContain('--project=setup');
    expect(args.at(-1)).toBe('--workers=1');
  });

  test('--project in the arguments is recognised in both spellings', () => {
    expect(hasProjectArg(['--project=api'])).toBe(true);
    expect(hasProjectArg(['--project', 'api'])).toBe(true);
    expect(hasProjectArg(['--grep', 'x'])).toBe(false);
  });
});

test.describe('per-phase JUnit files', () => {
  test('a base name gets the phase before its extension', () => {
    expect(phaseJunitFile('parallel', 'out/report.xml', '/suite')).toBe('out/report-parallel.xml');
    expect(phaseJunitFile('serial', '/ci/junit', '/suite')).toBe('/ci/junit-serial.xml');
  });

  test('without a base the file sits in .playwright', () => {
    expect(phaseJunitFile('serial', undefined, '/suite')).toBe(
      '/suite/.playwright/junit-serial.xml'
    );
    expect(phaseJunitFile('serial', '  ', '/suite')).toBe('/suite/.playwright/junit-serial.xml');
  });

  test('skipped tests are counted from the root attribute, else from the elements', () => {
    expect(
      countJunitSkipped(
        junit('<testsuites id="" name="" tests="9" failures="0" skipped="4" errors="0">')
      )
    ).toBe(4);
    expect(
      countJunitSkipped(
        junit(
          '<testsuites tests="2"><testcase><skipped/></testcase><testcase><skipped message="x"/></testcase></testsuites>'
        )
      )
    ).toBe(2);
    expect(countJunitSkipped(path.join(os.tmpdir(), 'phases-missing.xml'))).toBe(0);
  });
});

test.describe('the skip budget over both phases', () => {
  test('the sum is what is judged, not each phase', () => {
    expect(skipBudgetFailure(5, { MAX_SKIPPED: '5' })).toBeUndefined();
    expect(skipBudgetFailure(6, { MAX_SKIPPED: '5' })).toContain('MAX_SKIPPED=5 exceeded');
  });

  test('without MAX_SKIPPED only CI objects', () => {
    expect(skipBudgetFailure(100, {})).toBeUndefined();
    expect(skipBudgetFailure(0, { CI: '1' })).toContain('MAX_SKIPPED is not set');
  });
});

test.describe('exit code', () => {
  test('a failing first phase does not hide behind a passing second one', () => {
    expect(combinedExitCode([1, 0], false, true)).toBe(1);
    expect(combinedExitCode([0, 1], false, true)).toBe(1);
    expect(combinedExitCode([0, 0], false, true)).toBe(0);
  });

  test('the skip budget and the leak check fail an otherwise green run', () => {
    expect(combinedExitCode([0, 0], true, true)).toBe(1);
    expect(combinedExitCode([0, 0], false, false)).toBe(1);
  });
});

test.describe('Engine RAM', () => {
  test('ram_total is kB, as /proc/meminfo reports it', () => {
    expect(ramBytesFromMetrics({ data: { ram_total: 8_000_000 } })).toBe(8_000_000 * 1024);
    expect(ramBytesFromMetrics({ data: { ram_total: '3900000' } })).toBe(3_900_000 * 1024);
    expect(ramBytesFromMetrics({ data: {} })).toBeUndefined();
    expect(ramBytesFromMetrics(null)).toBeUndefined();
    expect(ramBytesFromMetrics({ data: { ram_total: 0 } })).toBeUndefined();
  });

  test('the token goes in the header, and every failure is "unknown"', async () => {
    const seen: { url: string; auth?: string }[] = [];
    const ok = await fetchEngineRamBytes('https://engine.test:2011/', 'secret-token', {
      fetch: (url, init) => {
        seen.push({ url, auth: init?.headers?.Authorization });
        return Promise.resolve(Response.json({ data: { ram_total: 1000 } }));
      },
    });
    expect(ok).toBe(1000 * 1024);
    expect(seen).toEqual([
      { url: 'https://engine.test:2011/metrics/current', auth: 'Bearer secret-token' },
    ]);

    for (const fetch of [
      () => Promise.resolve(new Response('no', { status: 401 })),
      () => Promise.reject(new Error('connect ECONNREFUSED secret-token')),
      () => Promise.resolve(new Response('<html>')),
    ]) {
      expect(
        await fetchEngineRamBytes('https://engine.test:2011', 'secret-token', { fetch })
      ).toBeUndefined();
    }
  });
});
