import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

import { isLoopbackIp } from '@/config/site-domain';

/**
 * Playwright's `show-report --host 0.0.0.0` still prints `http://localhost:…`
 * because that is how it turns a bind-all address into a clickable URL. Fine
 * on a laptop; useless when the suite ran on a VPS. The wrappers rewrite that
 * line to a host you can actually open from another machine.
 */

export function hostnameFromUrl(raw: string | undefined): string | undefined {
  const trimmed = raw?.trim();
  if (!trimmed) {
    return undefined;
  }

  try {
    return new URL(trimmed).hostname.replace(/^\[|\]$/g, '') || undefined;
  } catch {
    return undefined;
  }
}

/** Hosts that cannot be opened from another machine. */
export function isUnusableReportHost(host: string | undefined): boolean {
  const trimmed = host?.trim().replace(/^\[|\]$/g, '');
  if (!trimmed) {
    return true;
  }

  const lower = trimmed.toLowerCase();
  if (
    lower === 'localhost' ||
    lower === '0.0.0.0' ||
    lower === '::' ||
    lower === '[::]' ||
    lower.endsWith('.localhost')
  ) {
    return true;
  }

  return isLoopbackIp(trimmed);
}

/**
 * First candidate that is not loopback / bind-all. Order is the caller's
 * priority: REPORT_HOST, API_BASE_URL, env file, `hostname -f`, public IPv4.
 */
export function guessReportHost(candidates: (string | undefined)[]): string {
  for (const candidate of candidates) {
    const host = candidate?.trim().replace(/^\[|\]$/g, '');
    if (host && !isUnusableReportHost(host)) {
      return host;
    }
  }

  return '127.0.0.1';
}

/** IPv6 literals need brackets in a URL. */
export function formatHttpHost(host: string): string {
  const trimmed = host.trim().replace(/^\[|\]$/g, '');
  return trimmed.includes(':') ? `[${trimmed}]` : trimmed;
}

export function reportListenUrl(host: string, port: string): string {
  return `http://${formatHttpHost(host)}:${port}`;
}

function envFileApiBaseUrl(suiteRoot: string, testEnv?: string): string | undefined {
  const envFile = path.join(suiteRoot, 'env', testEnv ? `.env.${testEnv}` : '.env');
  if (!fs.existsSync(envFile)) {
    return undefined;
  }
  const match = /^API_BASE_URL=(.+)$/m.exec(fs.readFileSync(envFile, 'utf8'));
  return match?.[1]?.trim();
}

function osFqdn(): string | undefined {
  try {
    const out = execFileSync('hostname', ['-f'], { encoding: 'utf8', timeout: 2_000 }).trim();
    return out.length > 0 ? out : undefined;
  } catch {
    return undefined;
  }
}

function publicIpv4s(): string[] {
  const addresses: string[] = [];
  for (const nets of Object.values(os.networkInterfaces())) {
    for (const net of nets ?? []) {
      if (net.family === 'IPv4' && !net.internal) {
        addresses.push(net.address);
      }
    }
  }
  return addresses;
}

/**
 * Hostname or public IPv4 of this box — used when `bun run test` runs on the
 * engine itself and has to invent `API_BASE_URL` without being asked.
 */
export function guessThisMachineHost(): string {
  return guessReportHost([osFqdn(), ...publicIpv4s(), os.hostname()]);
}

/**
 * Host a laptop can open when the suite ran on this machine. Skips loopback
 * `API_BASE_URL` values that are typical of running the tests on the engine
 * itself.
 */
export function resolvePublicReportHost(
  suiteRoot: string,
  testEnv = process.env.TEST_ENV?.trim(),
  env: NodeJS.ProcessEnv = process.env
): string {
  return guessReportHost([
    env.REPORT_HOST,
    hostnameFromUrl(env.API_BASE_URL),
    hostnameFromUrl(envFileApiBaseUrl(suiteRoot, testEnv)),
    osFqdn(),
    ...publicIpv4s(),
    os.hostname(),
  ]);
}

/**
 * Rewrites Playwright's "Serving HTML report at http://localhost:9323" (and
 * the 0.0.0.0 / 127.0.0.1 variants) to the public host, including when the
 * line is wrapped in ANSI colour codes.
 */
export function rewriteLocalReportUrls(text: string, publicHost: string): string {
  const host = formatHttpHost(publicHost);
  return text.replace(
    /http:\/\/(?:localhost|127\.\d+\.\d+\.\d+|0\.0\.0\.0)(:\d+)?/gi,
    (_match, port: string | undefined) => `http://${host}${port ?? ''}`
  );
}
