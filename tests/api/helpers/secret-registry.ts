import { appendFileSync, chmodSync, mkdirSync, readdirSync, readFileSync, rmSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { usableSecrets } from './secret-values';

// Passwords and tokens the suite generates, or the Engine hands back, are not in the
// environment. A test registers them here: they are kept in memory for redactText and
// appended to a file the reporter process reads when it rewrites traces and the report.

const suiteRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/** Where each process writes the secrets it registered. */
export const SECRET_REGISTRY_DIR = path.join(suiteRoot, '.playwright', 'secrets');

const registered = new Set<string>();

/** Remembers `value` for redaction in this process and in the reporter; never throws. */
export function registerSecret(value: string | undefined | null): void {
  try {
    const [secret] = usableSecrets([value]);
    if (secret === undefined || registered.has(secret)) {
      return;
    }
    registered.add(secret);
    mkdirSync(SECRET_REGISTRY_DIR, { recursive: true });
    const file = path.join(SECRET_REGISTRY_DIR, `${process.pid}.txt`);
    appendFileSync(file, `${Buffer.from(secret, 'utf8').toString('base64')}\n`, { mode: 0o600 });
    chmodSync(file, 0o600);
  } catch {
    // A secret that cannot be persisted still stays redacted in this process.
  }
}

/** Secrets registered in this process, longest first. */
export function registeredSecrets(): string[] {
  return [...registered].sort((a, b) => b.length - a.length);
}

/** Secrets that any process wrote into `dir`, longest first; empty when there are none. */
export function readRegisteredSecretFiles(dir: string = SECRET_REGISTRY_DIR): string[] {
  try {
    const values: string[] = [];
    for (const name of readdirSync(dir)) {
      if (!name.endsWith('.txt')) {
        continue;
      }
      try {
        for (const line of readFileSync(path.join(dir, name), 'utf8').split('\n')) {
          if (line.trim() !== '') {
            values.push(Buffer.from(line.trim(), 'base64').toString('utf8'));
          }
        }
      } catch {
        // An unreadable file must not hide the others.
      }
    }
    return usableSecrets(values).sort((a, b) => b.length - a.length);
  } catch {
    return [];
  }
}

/** Removes `dir` and everything the processes registered in it. */
export function clearRegisteredSecretFiles(dir: string = SECRET_REGISTRY_DIR): void {
  try {
    rmSync(dir, { recursive: true, force: true });
  } catch {
    // Leftover files are cleared by the next run.
  }
}
