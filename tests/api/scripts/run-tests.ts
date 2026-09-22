import { spawn } from 'node:child_process';

import { ensureEngineEnv, suiteRoot } from './lib/engine-env';
import { reportListenUrl, resolvePublicReportHost } from './lib/report-host';

/**
 * The suite's front door: `npm test`.
 *
 * Makes sure there is an engine to point at — creating `env/.env` on the spot
 * when there is not — and then hands over to Playwright. Every argument after
 * `--` goes straight through, so `npm test -- tests/users --headed` behaves the
 * way `playwright test` would.
 *
 * `webserver-change`, `update`, `cli`, `engine-cert`, `network-mutation` and
 * `slow` are left out of the default run: they either reconfigure the engine,
 * need host SSH for `pae-artisan`, or take minutes of host-wide CSF / full
 * PHP-version matrix. The Supported-board catalogue runs here.
 */

const DEFAULT_PROJECTS = ['unit', 'api', 'supported-apps'];

function playwrightArgs(passthrough: string[]): string[] {
  const args = ['test'];

  // Only impose the default projects when the caller has not chosen their own.
  if (!passthrough.some((arg) => arg === '--project' || arg.startsWith('--project='))) {
    args.push(...DEFAULT_PROJECTS.flatMap((project) => [`--project=${project}`]));
  }

  return [...args, ...passthrough];
}

function printReportHint(): void {
  const envPort = process.env.REPORT_PORT?.trim();
  const port = envPort !== undefined && envPort.length > 0 ? envPort : '9323';
  const url = reportListenUrl(resolvePublicReportHost(suiteRoot), port);
  console.log(`\nHTML report: npm run report`);
  console.log(`  then open ${url}`);
  console.log(`  (or tunnel: ssh -L ${port}:127.0.0.1:${port} <user>@${new URL(url).hostname})\n`);
}

const passthrough = process.argv.slice(2);

await ensureEngineEnv(process.env.TEST_ENV?.trim()).catch((error: unknown) => {
  console.error(`\n${error instanceof Error ? error.message : String(error)}`);
  process.exit(1);
});

const child = spawn('playwright', playwrightArgs(passthrough), {
  stdio: 'inherit',
  shell: process.platform === 'win32',
  env: process.env,
});

child.on('exit', (code, signal) => {
  printReportHint();
  process.exit(signal ? 1 : (code ?? 1));
});
