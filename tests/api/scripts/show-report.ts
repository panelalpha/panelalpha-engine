import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import dotenv from 'dotenv';

import { testNetwork } from '@/helpers/test-network';

import { preparePlaywright } from './lib/playwright-cli';
import {
  reportListenUrl,
  resolvePublicReportHost,
  rewriteLocalReportUrls,
} from './lib/report-host';

/**
 * Serve the last HTML report in a way that works on a remote VPS.
 *
 * Playwright binds to `--host localhost` by default, and even bound to all
 * interfaces it prints `http://localhost:…` because that is how it turns a
 * bind-all address into a URL. This binds to all interfaces and rewrites that
 * line to the engine's public hostname.
 *
 * Safer alternative if you do not want port 9323 open on the public IP:
 *   ssh -L 9323:localhost:9323 root@<vps>
 *   bun run report -- --host localhost
 * then open http://localhost:9323 locally.
 */

const suiteRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const testEnv = process.env.TEST_ENV?.trim();

dotenv.config({ path: path.resolve(suiteRoot, 'env/.env'), quiet: true });
if (testEnv) {
  dotenv.config({
    path: path.resolve(suiteRoot, `env/.env.${testEnv}`),
    override: true,
    quiet: true,
  });
}

const reportDir = path.resolve(suiteRoot, '.playwright', testEnv ? `report-${testEnv}` : 'report');

const passthrough = process.argv.slice(2);

function hasFlag(name: string): boolean {
  return passthrough.some((arg) => arg === name || arg.startsWith(`${name}=`));
}

function flagValue(name: string): string | undefined {
  const idx = passthrough.indexOf(name);
  if (idx >= 0) {
    return passthrough[idx + 1];
  }
  const prefixed = passthrough.find((arg) => arg.startsWith(`${name}=`));
  return prefixed?.slice(name.length + 1);
}

if (!fs.existsSync(reportDir)) {
  console.error(`No report at ${path.relative(suiteRoot, reportDir)}. Run "bun run test" first.`);
  process.exit(1);
}

const host = hasFlag('--host') ? (flagValue('--host') ?? testNetwork.ANY) : testNetwork.ANY;
const envPort = process.env.REPORT_PORT?.trim();
const port = hasFlag('--port')
  ? (flagValue('--port') ?? '9323')
  : envPort !== undefined && envPort.length > 0
    ? envPort
    : '9323';
const publicHost = resolvePublicReportHost(suiteRoot, testEnv);
const publicUrl = reportListenUrl(publicHost, port);
const rewritePlaywrightUrl = host === testNetwork.ANY || host === '::';

const args = ['show-report', reportDir];
if (!hasFlag('--host')) {
  args.push('--host', host);
}
if (!hasFlag('--port')) {
  args.push('--port', port);
}
args.push(...passthrough);

console.log(`Report directory: ${path.relative(suiteRoot, reportDir)}`);
console.log(`Listening on ${host}:${port}`);
if (rewritePlaywrightUrl) {
  console.log(`Open from your machine:  ${publicUrl}`);
  console.log(
    `Or tunnel instead:         ssh -L ${port}:${testNetwork.LOOPBACK}:${port} <user>@${publicHost}`
  );
} else {
  console.log(`Open: ${reportListenUrl(host, port)}`);
}
console.log('');

const playwright = preparePlaywright();
const child = spawn(playwright.command, [...playwright.args, ...args], {
  stdio: rewritePlaywrightUrl ? ['inherit', 'pipe', 'inherit'] : 'inherit',
  cwd: suiteRoot,
  env: process.env,
});

if (rewritePlaywrightUrl && child.stdout) {
  child.stdout.on('data', (chunk: Buffer) => {
    process.stdout.write(rewriteLocalReportUrls(chunk.toString(), publicHost));
  });
}

child.on('exit', (code, signal) => {
  process.exit(signal ? 1 : (code ?? 1));
});
