import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import readline from 'node:readline/promises';
import { fileURLToPath } from 'node:url';

import { insecureFetch } from '@/helpers/insecure-fetch';
import { formatHttpHost, guessThisMachineHost } from './report-host';

/**
 * Creating and checking the `env/.env` that points the suite at an engine.
 *
 * On the engine host itself this needs no questions: the compose file is
 * sitting next to the suite, `hostname -f` (or the public IPv4) is the API
 * host, and `pae-artisan` mints the token in-process. From a laptop it asks
 * which engine, then mints over SSH. Either way the token is checked against
 * the live API before the file is written, so a wrong host fails here instead
 * of as several hundred 401s later.
 */

export const ENGINE_API_PORT = 2011;
export const ENGINE_COMPOSE_FILE = '/opt/panelalpha/shared-hosting/docker-compose.yml';

const VERIFY_TIMEOUT_MS = 15_000;

export const suiteRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

export interface EngineEnvOptions {
  host?: string;
  token?: string;
  profile?: string;
  port: number;
  sshUser: string;
  sshKey?: string;
  tokenName: string;
  force: boolean;
  verify: boolean;
}

export function defaultOptions(): EngineEnvOptions {
  return {
    port: ENGINE_API_PORT,
    sshUser: 'root',
    tokenName: 'api-tests',
    force: false,
    verify: true,
  };
}

export function envFilePath(profile?: string): string {
  return path.join(suiteRoot, 'env', profile ? `.env.${profile}` : '.env');
}

/** Sanctum prints `{id}|{plaintext}`; docker/SSH noise around it is ignored. */
export function parseSanctumToken(output: string): string | undefined {
  return output
    .split('\n')
    .map((line) => line.trim())
    .find((line) => /^\d+\|\S+$/.test(line));
}

export function parseEnvCredentials(content: string): { apiBaseUrl?: string; token?: string } {
  const apiBaseUrl = /^API_BASE_URL=(.+)$/m.exec(content)?.[1]?.trim();
  const token = /^API_TOKEN=(.+)$/m.exec(content)?.[1]?.trim();
  return {
    apiBaseUrl: apiBaseUrl && apiBaseUrl.length > 0 ? apiBaseUrl : undefined,
    token: token && token.length > 0 ? token : undefined,
  };
}

export function envFileHasCredentials(file: string): boolean {
  if (!fs.existsSync(file)) {
    return false;
  }
  const parsed = parseEnvCredentials(fs.readFileSync(file, 'utf8'));
  return Boolean(parsed.apiBaseUrl && parsed.token);
}

/** True when this process is sitting on an installed engine, not a laptop. */
export function isLocalEngine(composeFile = ENGINE_COMPOSE_FILE): boolean {
  return fs.existsSync(composeFile);
}

/** Prefer the installed wrapper; fall back to a direct compose exec. */
export function engineTokenCommand(tokenName = 'api-tests'): string {
  return `pae-artisan api:token:create ${tokenName} --short`;
}

export function engineTokenCommandFallback(tokenName = 'api-tests'): string {
  return (
    `docker compose -f ${ENGINE_COMPOSE_FILE} exec -T core ` +
    `php artisan api:token:create ${tokenName} --short`
  );
}

function runAndParseToken(file: string, args: string[]): string | undefined {
  try {
    const output = execFileSync(file, args, {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: 60_000,
    });
    return parseSanctumToken(output);
  } catch {
    return undefined;
  }
}

/**
 * Mints a token with artisan on this machine. Used when `bun run test` runs on
 * the engine itself — SSH to root@localhost is the common way that "automatic"
 * setup used to fail.
 */
export function mintTokenLocal(tokenName = 'api-tests'): string | undefined {
  return (
    runAndParseToken('pae-artisan', ['api:token:create', tokenName, '--short']) ??
    runAndParseToken('docker', [
      'compose',
      '-f',
      ENGINE_COMPOSE_FILE,
      'exec',
      '-T',
      'core',
      'php',
      'artisan',
      'api:token:create',
      tokenName,
      '--short',
    ])
  );
}

/**
 * Mints a token by running artisan on a remote engine host over SSH.
 *
 * Prefers `pae-artisan`; falls back to a direct `docker compose exec` when the
 * wrapper is not on PATH. Returns `undefined` rather than throwing: having no
 * SSH access is an ordinary situation, and the caller asks for a token.
 */
export function mintTokenOverSsh(options: EngineEnvOptions & { host: string }): string | undefined {
  return (
    mintTokenOverSshWithCommand(options, engineTokenCommand(options.tokenName)) ??
    mintTokenOverSshWithCommand(options, engineTokenCommandFallback(options.tokenName))
  );
}

function mintTokenOverSshWithCommand(
  options: EngineEnvOptions & { host: string },
  command: string
): string | undefined {
  const args = [
    ...(options.sshKey ? ['-i', options.sshKey] : []),
    '-o',
    'BatchMode=yes',
    '-o',
    'StrictHostKeyChecking=accept-new',
    '-o',
    'ConnectTimeout=10',
    `${options.sshUser}@${options.host}`,
    command,
  ];

  return runAndParseToken('ssh', args);
}

/** Calls the engine's own liveness endpoint with the token about to be stored. */
export async function verifyConnection(apiBaseUrl: string, token: string): Promise<void> {
  let response: Response;

  try {
    response = await insecureFetch(`${apiBaseUrl}test-connection`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      signal: AbortSignal.timeout(VERIFY_TIMEOUT_MS),
    });
  } catch (error) {
    // A refused connection, an unknown host and a timeout all land here, and the
    // raw message ("The operation was aborted due to timeout") says nothing
    // about what to do next.
    throw new Error(
      `Could not reach ${apiBaseUrl} (${error instanceof Error ? error.message : String(error)}).\n` +
        'Check that:\n' +
        `  - the engine is running    ssh <host> 'docker compose -f ${ENGINE_COMPOSE_FILE} ps'\n` +
        `  - port ${new URL(apiBaseUrl).port} is reachable from here, and open in the firewall\n` +
        '  - the host is spelled correctly\n' +
        'Pass --no-verify to write the file anyway.'
    );
  }

  if (response.status === 401 || response.status === 403) {
    throw new Error(
      `The engine rejected the token (HTTP ${response.status}). Mint a fresh one on the host:\n` +
        `  ${engineTokenCommand()}`
    );
  }

  if (!response.ok) {
    throw new Error(
      `${apiBaseUrl}test-connection answered HTTP ${response.status}. ` +
        'Check the host and port, and that the engine is running.'
    );
  }
}

/**
 * Note the absence of DOMAIN: extra test names hang off the engine's
 * panelalpha.direct / cert_domain zone, derived at run time from GET
 * /system/info. Set DOMAIN by hand only to override that.
 */
function renderEnvFile(values: { apiBaseUrl: string; token: string; profile?: string }): string {
  const profileNote = values.profile
    ? `# Profile "${values.profile}" — run it with TEST_ENV=${values.profile}.\n`
    : '';

  return `# Written by \`bun run env:setup\`. Safe to edit by hand.
# Every option is documented in env/.env.example.
${profileNote}
API_BASE_URL=${values.apiBaseUrl}
API_TOKEN=${values.token}
`;
}

/** Shows enough of a token to recognise it, without putting it in a log. */
export function maskToken(token: string): string {
  return `${token.split('|')[0]}|${'•'.repeat(8)}`;
}

export interface WriteResult {
  file: string;
  apiBaseUrl: string;
  token: string;
}

function obtainToken(options: EngineEnvOptions, host: string): string | undefined {
  if (options.token) {
    return options.token;
  }

  if (isLocalEngine()) {
    console.log('Minting an API token with pae-artisan…');
    const local = mintTokenLocal(options.tokenName);
    if (local) {
      console.log(`  got ${maskToken(local)}`);
      return local;
    }
    console.log('  local artisan is not available, trying SSH…');
  }

  console.log(`Minting an API token on ${host} over SSH…`);
  const remote = mintTokenOverSsh({ ...options, host });
  if (remote) {
    console.log(`  got ${maskToken(remote)}`);
    return remote;
  }

  return undefined;
}

/**
 * Walks through host, token and verification, then writes the env file.
 *
 * On the engine host the host and token are invented without a prompt. From a
 * laptop it asks, unless `--host` / `--token` were passed. In CI a missing
 * value is an error rather than a hang.
 */
export async function writeEngineEnv(options: EngineEnvOptions): Promise<WriteResult> {
  const interactive = process.stdin.isTTY === true;
  const rl = interactive
    ? readline.createInterface({ input: process.stdin, output: process.stdout })
    : undefined;

  try {
    let host = options.host;
    if (!host && isLocalEngine()) {
      host = guessThisMachineHost();
      console.log(`This is the engine host — using ${host}`);
    }
    if (!host && rl) {
      host = (await rl.question('Engine host (IP address or hostname): ')).trim();
    }
    if (!host) {
      throw new Error('No engine host given. Pass --host, or run on the engine host.');
    }

    const file = envFilePath(options.profile);
    if (fs.existsSync(file) && !options.force) {
      throw new Error(
        `${path.relative(suiteRoot, file)} already exists. Pass --force to overwrite it.`
      );
    }

    let token = obtainToken(options, host);
    if (!token && rl && !options.token) {
      console.log(
        '  no SSH access. Run this on the engine host and paste the result:\n' +
          `    ${engineTokenCommand(options.tokenName)}`
      );
      token = (await rl.question('API token: ')).trim();
    }

    if (!token) {
      throw new Error(
        `Could not obtain an API token. Run this on ${host} and pass the result as --token:\n` +
          `  ${engineTokenCommand(options.tokenName)}`
      );
    }

    const apiBaseUrl = `https://${formatHttpHost(host)}:${options.port}/api/`;

    if (options.verify) {
      console.log(`Checking ${apiBaseUrl}test-connection …`);
      await verifyConnection(apiBaseUrl, token);
      console.log('  the engine answered.');
    }

    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, renderEnvFile({ apiBaseUrl, token, profile: options.profile }), 'utf8');

    return { file, apiBaseUrl, token };
  } finally {
    rl?.close();
  }
}

/**
 * Makes sure the suite has somewhere to point before a run starts.
 *
 * Already true when the env file has credentials, or CI passed the variables.
 * Otherwise a local engine is provisioned with no prompt; a laptop still needs
 * a terminal (or `--host`) so we do not hang in CI.
 */
export async function ensureEngineEnv(profile?: string): Promise<void> {
  const file = envFilePath(profile);
  if (envFileHasCredentials(file)) {
    return;
  }

  if (process.env.API_BASE_URL?.trim() && process.env.API_TOKEN?.trim()) {
    return;
  }

  const relative = path.relative(suiteRoot, file);
  const local = isLocalEngine();

  if (!local && process.stdin.isTTY !== true) {
    throw new Error(
      `${relative} is missing and there is no terminal to ask on.\n` +
        'Either set API_BASE_URL and API_TOKEN in the environment, or create the file:\n' +
        `  bun run env:setup -- --host <engine-host>${profile ? ` --profile ${profile}` : ''}`
    );
  }

  if (local) {
    console.log(`${relative} is missing — detected the local engine, creating it.\n`);
  } else {
    console.log(`${relative} is missing — let's create it.\n`);
  }

  const result = await writeEngineEnv({
    ...defaultOptions(),
    profile,
    force: fs.existsSync(file),
  });
  console.log(`\nWrote ${path.relative(suiteRoot, result.file)}\n`);
}
