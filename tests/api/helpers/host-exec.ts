import { execFile } from 'node:child_process';
import { access } from 'node:fs/promises';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);
const EXEC_TIMEOUT_MS = 120_000;

export interface HostExecResult {
  exitCode: number;
  stdout: string;
  stderr: string;
}

export type HostExecMode = 'local' | 'ssh';

export interface HostExec {
  available: true;
  mode: HostExecMode;
  paeBin: string;
  pae(args: string[]): Promise<HostExecResult>;
  run(command: string, args: string[]): Promise<HostExecResult>;
}

function posixQuote(value: string): string {
  return `'${value.replace(/'/g, `'\\''`)}'`;
}

function resultFromExecError(error: unknown): HostExecResult | null {
  if (error === null || typeof error !== 'object') {
    return null;
  }
  const execError = error as {
    code?: number | string;
    stdout?: string;
    stderr?: string;
  };
  if (typeof execError.code === 'number') {
    return {
      exitCode: execError.code,
      stdout: execError.stdout ?? '',
      stderr: execError.stderr ?? '',
    };
  }
  return null;
}

async function runExecFile(file: string, args: string[]): Promise<HostExecResult> {
  try {
    const { stdout, stderr } = await execFileAsync(file, args, {
      timeout: EXEC_TIMEOUT_MS,
      maxBuffer: 10 * 1024 * 1024,
      encoding: 'utf8',
    });
    return { exitCode: 0, stdout, stderr };
  } catch (error) {
    const failed = resultFromExecError(error);
    if (failed) {
      return failed;
    }
    throw error;
  }
}

async function commandExists(bin: string): Promise<boolean> {
  if (bin.includes('/')) {
    try {
      await access(bin);
      return true;
    } catch {
      return false;
    }
  }

  const which = await runExecFile('which', [bin]).catch(() => null);
  return which !== null && which.exitCode === 0 && which.stdout.trim().length > 0;
}

function nonEmptyEnv(name: string): string | undefined {
  const value = process.env[name]?.trim();
  return value !== undefined && value.length > 0 ? value : undefined;
}

function sshArgs(host: string): string[] {
  const user = nonEmptyEnv('ENGINE_SSH_USER') ?? 'root';
  const key = nonEmptyEnv('ENGINE_SSH_KEY');
  return [
    ...(key ? ['-i', key] : []),
    '-o',
    'BatchMode=yes',
    '-o',
    'StrictHostKeyChecking=accept-new',
    '-o',
    'ConnectTimeout=10',
    `${user}@${host}`,
  ];
}

function engineSshHost(): string | undefined {
  const explicit = nonEmptyEnv('ENGINE_SSH_HOST');
  if (explicit) {
    return explicit;
  }
  const base = process.env.API_BASE_URL?.trim();
  if (!base) {
    return undefined;
  }
  try {
    return new URL(base).hostname;
  } catch {
    return undefined;
  }
}

function paeCandidates(): string[] {
  const fromEnv = nonEmptyEnv('PAE_BIN');
  return [...(fromEnv ? [fromEnv] : []), 'pae-artisan', 'pae', '/usr/local/bin/pae-artisan'];
}

async function resolveLocalPae(): Promise<string | undefined> {
  for (const candidate of paeCandidates()) {
    if (await commandExists(candidate)) {
      return candidate;
    }
  }
  return undefined;
}

let resolved: Promise<HostExec | null> | undefined;

/**
 * Resolves how to run `pae-artisan` from this runner: locally when the suite is
 * on the engine host, otherwise over SSH using the same knobs as env setup.
 */
export async function resolveHostExec(): Promise<HostExec | null> {
  resolved ??= resolveHostExecUncached();
  return resolved;
}

async function resolveHostExecUncached(): Promise<HostExec | null> {
  const localBin = await resolveLocalPae();
  if (localBin) {
    return {
      available: true,
      mode: 'local',
      paeBin: localBin,
      pae: (args) => runExecFile(localBin, args),
      run: (command, args) => runExecFile(command, args),
    };
  }

  const host = engineSshHost();
  if (!host) {
    return null;
  }

  const remoteBin = nonEmptyEnv('PAE_BIN') ?? 'pae-artisan';
  const remote = {
    available: true as const,
    mode: 'ssh' as const,
    paeBin: remoteBin,
    pae: (args: string[]) => {
      const command = [remoteBin, ...args].map(posixQuote).join(' ');
      return runExecFile('ssh', [...sshArgs(host), command]);
    },
    run: (command: string, args: string[]) => {
      const remoteCommand = [command, ...args].map(posixQuote).join(' ');
      return runExecFile('ssh', [...sshArgs(host), remoteCommand]);
    },
  };

  const probe = await remote.pae(['list']).catch(() => null);
  if (probe?.exitCode !== 0) {
    return null;
  }

  return remote;
}

const ENGINE_COMPOSE_FILE = '/opt/panelalpha/shared-hosting/docker-compose.yml';

/**
 * Writes a small file where `project:file:upload` can see it: inside the
 * engine `core` container when artisan is wrapped by compose, otherwise on
 * the host PATH that `pae-artisan` uses.
 */
export async function stageFileForArtisan(
  hostExec: HostExec,
  filename: string,
  contents: string
): Promise<string | null> {
  const path = `/tmp/${filename}`;
  const quoted = JSON.stringify(contents);
  const inCore = await hostExec.run('docker', [
    'compose',
    '-f',
    ENGINE_COMPOSE_FILE,
    'exec',
    '-T',
    'core',
    'sh',
    '-c',
    `printf '%s' ${quoted} > ${path}`,
  ]);
  if (inCore.exitCode === 0) {
    return path;
  }
  const onHost = await hostExec.run('sh', ['-c', `printf '%s' ${quoted} > ${path}`]);
  return onHost.exitCode === 0 ? path : null;
}

/** Removes a file {@link stageFileForArtisan} wrote, wherever it went. */
export async function unstageFileForArtisan(hostExec: HostExec, path: string): Promise<void> {
  await hostExec.run('docker', [
    'compose',
    '-f',
    ENGINE_COMPOSE_FILE,
    'exec',
    '-T',
    'core',
    'rm',
    '-f',
    path,
  ]);
  await hostExec.run('rm', ['-f', path]);
}
