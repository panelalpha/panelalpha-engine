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

/** A session left open, so a test can watch the Engine end it. */
export interface SftpSession {
  /** Lists `/`; rejects once the Engine has closed the session. */
  list(): Promise<unknown[]>;
  /** True once the connection reported that the server closed it. */
  isClosed(): boolean;
  close(): Promise<void>;
}

/** Connects and keeps the session open; the caller closes it. */
export async function openSftpSession(credentials: SftpCredentials): Promise<SftpSession> {
  registerSecret(credentials.password);
  const client = new SftpClient();
  let closed = false;
  const markClosed = () => {
    closed = true;
  };
  client.on('close', markClosed);
  client.on('end', markClosed);
  await client.connect(credentials);

  return {
    list: () => client.list('/'),
    isClosed: () => closed,
    close: async () => {
      await client.end().catch(() => undefined);
    },
  };
}

/**
 * Polls until a held-open session can be established. An account that the
 * Engine has not synced yet refuses the login; that is retried until `timeout`.
 */
export async function waitForOpenSftpSession(
  credentials: SftpCredentials,
  options: { timeout: number; interval?: number }
): Promise<SftpSession> {
  let opened: SftpSession | undefined;
  let last = 'no login attempted yet';
  await waitForCondition(
    async () => {
      try {
        opened = await openSftpSession(credentials);
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

  if (opened === undefined) {
    throw new Error(`SFTP login as ${credentials.username} never succeeded`);
  }
  return opened;
}

/**
 * Waits until the session no longer answers. A live session lists `/`; one the
 * server has ended rejects, or the connection has already reported it closed.
 */
export async function waitForSftpSessionClosed(
  session: SftpSession,
  options: { timeout: number; interval?: number }
): Promise<void> {
  await waitForCondition(
    async () => {
      if (session.isClosed()) {
        return true;
      }
      try {
        await Promise.race([
          session.list(),
          new Promise((_, reject) =>
            setTimeout(() => reject(new Error('the list request did not answer')), 5_000)
          ),
        ]);
        return false;
      } catch {
        return true;
      }
    },
    {
      timeout: options.timeout,
      interval: options.interval ?? 500,
      message: 'the SFTP session was never closed',
    }
  );
}
