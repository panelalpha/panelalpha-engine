import path from 'node:path';

import { describeError } from './lib/leak-check/snapshot';
import {
  compareWithSetupAllow,
  formatSummary,
  readSnapshot,
  snapshotEngine,
  writeJson,
} from './lib/leak-check/run';

/**
 * Proves a test run removed what it created.
 *
 *   leak-check snapshot <file>
 *   leak-check compare <before> <after> [--json <out>]
 *
 * `bun run test` does both on its own when LEAK_CHECK=1 (or CI is set). `compare`
 * exits 1 on any leak, changed setting or unreadable section.
 */

const USAGE = [
  'Usage:',
  '  bun run leak-check -- snapshot <file>',
  '  bun run leak-check -- compare <before> <after> [--json <out>]',
].join('\n');

async function main(argv: string[]): Promise<number> {
  const [command, ...rest] = argv;

  if (command === 'snapshot' && rest.length === 1) {
    const file = path.resolve(rest[0]);
    const snapshot = await snapshotEngine();
    writeJson(file, snapshot);
    console.log(`Snapshot of ${Object.keys(snapshot.sections).length} sections written to ${file}`);
    return 0;
  }

  if (command === 'compare') {
    const jsonAt = rest.indexOf('--json');
    const out = jsonAt >= 0 ? rest[jsonAt + 1] : undefined;
    const files = jsonAt >= 0 ? [...rest.slice(0, jsonAt), ...rest.slice(jsonAt + 2)] : rest;
    if (files.length === 2 && (jsonAt < 0 || out)) {
      const result = compareWithSetupAllow(readSnapshot(files[0]), readSnapshot(files[1]));
      console.log(formatSummary(result));
      if (out) {
        writeJson(path.resolve(out), result);
      }
      return result.ok ? 0 : 1;
    }
  }

  console.error(USAGE);
  return 2;
}

process.exit(
  await main(process.argv.slice(2)).catch((error: unknown) => {
    console.error(`leak-check failed: ${describeError(error)}`);
    return 1;
  })
);
