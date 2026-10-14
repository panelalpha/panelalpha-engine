import { Client as FtpClient } from 'basic-ftp';

import { registerSecret } from './secret-registry';

export const QUOTA_TEST_SIZE_MB = 1;
export const UPDATED_FTP_PASSWORD = 'UpdatedFtpPass456!';
registerSecret(UPDATED_FTP_PASSWORD);

export interface FtpCredentials {
  host: string;
  user: string;
  password: string;
}

/** Opens an FTP session, runs `body`, and always closes the socket. */
export async function withFtpClient<T>(body: (client: FtpClient) => Promise<T>): Promise<T> {
  const client = new FtpClient();
  try {
    return await body(client);
  } finally {
    client.close();
  }
}

/** Logs in and lists the root directory — the smallest end-to-end FTP check. */
export async function ftpLoginAndList({ host, user, password }: FtpCredentials): Promise<void> {
  registerSecret(password);
  await withFtpClient(async (client) => {
    await client.access({ host, user, password, secure: false });
    await client.list();
  });
}

/** Whether the given credentials are accepted by the FTP server. */
export async function ftpLoginSucceeds(credentials: FtpCredentials): Promise<boolean> {
  try {
    await ftpLoginAndList(credentials);
    return true;
  } catch {
    return false;
  }
}

/**
 * Splits a pure-ftpd greeting into its non-empty lines.
 *
 * The engine is expected to serve a reduced banner: a bare 220 and at most one
 * more line, with none of pure-ftpd's default chatter about the local time, the
 * user number, or IPv6 support.
 */
export function bannerLines(message: string): string[] {
  return message
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean);
}

export const CHATTY_BANNER_PATTERN =
  /pure-ftpd|user number|local time|private system|ipv6 connections|disconnected after/i;
