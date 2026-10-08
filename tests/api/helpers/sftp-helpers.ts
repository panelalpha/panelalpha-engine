import SftpClient from 'ssh2-sftp-client';

import { redactAndTruncate } from './redact';
import { waitForCondition } from './retry';
import { registerSecret } from './secret-registry';

export type NormalizedSftpAuth = 'password' | 'key';

interface SftpAuthFields {
  auth_type?: string;
  auth_method?: string;
}

/**
 * Collapses the engine's auth fields to one value.
 *
 * Depending on version and endpoint, the auth mode arrives as `auth_type` or
 * `auth_method`, and the key mode is spelled `key` or `public_key`.
 */
export function normalizeSftpAuth(
  account: SftpAuthFields | undefined
): NormalizedSftpAuth | undefined {
  const raw = account?.auth_type ?? account?.auth_method;
  if (raw === 'password') {
    return 'password';
  }
  if (raw === 'key' || raw === 'public_key') {
    return 'key';
  }
  return undefined;
}

/** Spells out both raw fields, so a mismatch says what the engine actually sent. */
export function formatSftpAuthState(account: SftpAuthFields | undefined): string {
  if (!account) {
    return 'account not found in the listing';
  }
  return (
    `auth_type=${account.auth_type ?? '(none)'}, ` +
    `auth_method=${account.auth_method ?? '(none)'}, ` +
    `normalized=${normalizeSftpAuth(account) ?? 'unknown'}`
  );
}

export interface SftpCredentials {
  host: string;
  port: number;
  username: string;
  password: string;
}

/** Connects, lists `/`, and always closes the session. */
export async function sftpLoginAndList(credentials: SftpCredentials): Promise<unknown[]> {
  registerSecret(credentials.password);
  const client = new SftpClient();
  try {
    await client.connect(credentials);
    return await client.list('/');
  } finally {
    await client.end();
  }
}

/**
 * Polls until a login with these credentials succeeds, and returns the listing
 * of `/`. A refused login (the account or password not applied yet) is retried
 * until `timeout`; the last refusal is part of the failure.
 */
export async function waitForSftpLogin(
  credentials: SftpCredentials,
  options: { timeout: number; interval?: number }
): Promise<unknown[]> {
  let listing: unknown[] = [];
  let last = 'no login attempted yet';
  await waitForCondition(
    async () => {
      try {
        listing = await sftpLoginAndList(credentials);
        return true;
      } catch (error) {
        last = `login failed: ${redactAndTruncate(error instanceof Error ? error.message : String(error), 300)}`;
        return false;
      }
    },
    {
      timeout: options.timeout,
      interval: options.interval ?? 1_000,
      message: `SFTP login as ${credentials.username} never succeeded`,
      describeLast: () => last,
    }
  );
  return listing;
}
