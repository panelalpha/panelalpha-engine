import { expect } from '@playwright/test';
import type { HostExec } from './host-exec';

/**
 * Reaching the engine from where a project's application runs.
 *
 * The account container is a Docker host of its own, so the application is a
 * container inside it: `docker exec <account> docker exec <service> sh -lc …`
 * is how a spec asks the deployed app what it sees. Traffic leaves that nested
 * container through the account's own address on `pash-tenants`, which is the
 * address the tenant firewall judges.
 */

/** The app service of the generated run file, as compose names its container. */
const APP_CONTAINER = 'project-app-1';

export interface ExecResult {
  exitCode: number;
  stdout: string;
  stderr: string;
}

/** Runs `script` in the deployed application's container. */
export async function execInApp(
  hostExec: HostExec,
  account: string,
  script: string
): Promise<ExecResult> {
  return hostExec.run('docker', [
    'exec',
    account,
    'docker',
    'exec',
    APP_CONTAINER,
    'sh',
    '-lc',
    script,
  ]);
}

/** Runs `script` in the account container itself, beside the application. */
export async function execInAccount(
  hostExec: HostExec,
  account: string,
  script: string
): Promise<ExecResult> {
  return hostExec.run('docker', ['exec', account, 'sh', '-lc', script]);
}

/** The value of one environment variable inside the application, or null when it is unset. */
export async function appEnv(
  hostExec: HostExec,
  account: string,
  name: string
): Promise<string | null> {
  const result = await execInApp(hostExec, account, `printenv ${name} || true`);

  return result.stdout.trim() === '' ? null : result.stdout.trim();
}

/**
 * Asks the engine for `path` with the token the application was given, from
 * inside that application, and answers the status code. The certificate the
 * engine serves is passed when it was handed over; a trusted one is already in
 * the image's store.
 */
export async function engineApiStatus(
  hostExec: HostExec,
  account: string,
  path: string
): Promise<string> {
  const script = [
    'set -e',
    'CA=""',
    'if [ -n "$PANELALPHA_ENGINE_CA" ]; then',
    '  printf "%s\\n" "$PANELALPHA_ENGINE_CA" > /tmp/pa-engine-ca.pem',
    '  CA="--cacert /tmp/pa-engine-ca.pem"',
    'fi',
    'curl -sS $CA -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $PANELALPHA_ENGINE_TOKEN" "$PANELALPHA_ENGINE_URL' +
      path +
      '"',
  ].join('\n');

  const result = await execInApp(hostExec, account, script);

  return result.stdout.trim();
}

/**
 * The application holds what the engine promised it: an address on the engine's
 * API port, and a token.
 */
export async function expectAppCarriesApiAccess(
  hostExec: HostExec,
  account: string
): Promise<void> {
  const url = await appEnv(hostExec, account, 'PANELALPHA_ENGINE_URL');
  const token = await appEnv(hostExec, account, 'PANELALPHA_ENGINE_TOKEN');

  expect(url, 'PANELALPHA_ENGINE_URL is set').toMatch(/^https:\/\/[^/]+:2011\/api$/);
  expect(token, 'PANELALPHA_ENGINE_TOKEN is set').toMatch(/^\d+\|/);
}
