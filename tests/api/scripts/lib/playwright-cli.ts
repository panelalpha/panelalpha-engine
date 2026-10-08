import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';

/**
 * How the helper scripts start Playwright.
 *
 * The scripts themselves may run under Bun, but Playwright Test always runs under Node: the child is
 * `node <@playwright/test cli.js>`, never the `playwright` bin and never `bun --bun`. This file is
 * imported by unit tests, so it only uses `node:*` APIs.
 */

export interface PlaywrightLaunch {
  command: string;
  /** Leading arguments; the Playwright arguments follow. */
  args: string[];
}

export interface RuntimeProbe {
  node: string;
  bun: string | null;
  releaseName: string;
}

const probeSource =
  'console.log(JSON.stringify({node:process.versions.node,bun:process.versions.bun??null,releaseName:process.release.name}))';

/** The Node binary for the child. `process.execPath` is Bun when the script runs under Bun. */
export function nodeBinary(
  versions: Record<string, string | undefined> = process.versions
): string {
  return versions.bun ? 'node' : process.execPath;
}

export function playwrightLaunch(
  nodeCommand: string = nodeBinary(),
  resolveCli: () => string = () => createRequire(import.meta.url).resolve('@playwright/test/cli')
): PlaywrightLaunch {
  return { command: nodeCommand, args: [resolveCli()] };
}

/** Ask the interpreter that will run Playwright what it is. */
export function probeRuntime(command: string, env: NodeJS.ProcessEnv = process.env): RuntimeProbe {
  const result = spawnSync(command, ['-e', probeSource], { encoding: 'utf-8', env });
  if (result.error || result.status !== 0) {
    throw new Error(
      `Cannot start \`${command}\` to run Playwright: ${result.error?.message ?? result.stderr.trim()}. ` +
        'Playwright Test needs Node >= 20.19 on PATH.'
    );
  }
  return JSON.parse(result.stdout.trim()) as RuntimeProbe;
}

/** Throws unless the probed runtime is Node; returns the line the runners print. */
export function describePlaywrightRuntime(probe: RuntimeProbe): string {
  if (probe.bun || probe.releaseName !== 'node') {
    throw new Error(
      `Playwright would run under ${probe.bun ? `Bun ${probe.bun}` : probe.releaseName}, not Node. ` +
        'Put a real Node >= 20.19 first on PATH; do not run Playwright with `bun --bun`.'
    );
  }
  return `Playwright runs under Node ${probe.node}`;
}

/** Resolve the launch command, verify it is Node and print the runtime line. */
export function preparePlaywright(log: (line: string) => void = console.log): PlaywrightLaunch {
  const launch = playwrightLaunch();
  log(describePlaywrightRuntime(probeRuntime(launch.command)));
  return launch;
}
