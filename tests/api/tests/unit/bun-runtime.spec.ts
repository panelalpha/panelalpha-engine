import fs from 'node:fs';
import path from 'node:path';
import { expect, test } from '@/fixtures/test-options';
import {
  describePlaywrightRuntime,
  nodeBinary,
  playwrightLaunch,
  probeRuntime,
} from '../../scripts/lib/playwright-cli';

const suiteRoot = path.resolve(import.meta.dirname, '..', '..');

test.describe('helper scripts under Bun, Playwright under Node', () => {
  test('the child is Node even when the script runs under Bun', () => {
    expect(nodeBinary({ bun: '1.4.2' })).toBe('node');
    expect(nodeBinary({})).toBe(process.execPath);
  });

  test('launch runs the @playwright/test CLI file, not a bin that Bun could take over', () => {
    const launch = playwrightLaunch('node', () => '/x/node_modules/@playwright/test/cli.js');
    expect(launch).toEqual({ command: 'node', args: ['/x/node_modules/@playwright/test/cli.js'] });
    const real = playwrightLaunch();
    expect(path.basename(real.args[0] ?? '')).toBe('cli.js');
    expect(fs.existsSync(real.args[0] ?? '')).toBe(true);
  });

  test('the probed child reports Node and the runner prints its version', () => {
    const probe = probeRuntime(playwrightLaunch().command);
    expect(probe.bun).toBeNull();
    expect(probe.releaseName).toBe('node');
    expect(describePlaywrightRuntime(probe)).toBe(`Playwright runs under Node ${probe.node}`);
  });

  test('a Bun child is refused with a clear message', () => {
    expect(() =>
      describePlaywrightRuntime({ node: '24.3.0', bun: '1.4.2', releaseName: 'node' })
    ).toThrow(/Bun 1\.4\.2, not Node/);
  });

  test('a command that cannot start is reported', () => {
    expect(() => probeRuntime('definitely-not-a-node-binary')).toThrow(/Cannot start/);
  });

  test('scripts/lib stays on node:* APIs', () => {
    const files: string[] = [];
    const walk = (dir: string): void => {
      for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
          walk(full);
        } else if (entry.name.endsWith('.ts')) {
          files.push(full);
        }
      }
    };
    walk(path.join(suiteRoot, 'scripts', 'lib'));
    expect(files.length).toBeGreaterThan(0);
    for (const file of files) {
      const text = fs.readFileSync(file, 'utf-8');
      expect(text, path.relative(suiteRoot, file)).not.toMatch(
        /\bBun\.[a-z]|from ['"]bun:|import\(['"]bun:/
      );
    }
  });
});
