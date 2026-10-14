import { registerSecret } from './secret-registry';

export const TEST_PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as const;
export const LIMITED_PRIVILEGES = ['SELECT', 'INSERT'] as const;
export const UPDATED_DB_PASSWORD = 'UpdatedDbPass456!';
registerSecret(UPDATED_DB_PASSWORD);

/**
 * Flattens whatever the privileges endpoint returned into one searchable string.
 *
 * Engines answer with an array, a comma-joined string, or an object keyed by
 * privilege, so callers assert with `toContain` against this instead of
 * branching on the shape.
 */
export function privilegesAsText(body: unknown): string {
  const privileges =
    (typeof body === 'object' && body !== null
      ? (body as { privileges?: unknown }).privileges
      : undefined) ??
    body ??
    '';

  if (Array.isArray(privileges)) {
    return privileges.map(String).join(',');
  }
  if (typeof privileges === 'string') {
    return privileges;
  }
  return JSON.stringify(privileges);
}

/**
 * The name the engine actually created, which is prefixed with the account name
 * and so rarely matches what was requested.
 */
export function createdMySqlName(body: unknown): string | undefined {
  if (typeof body !== 'object' || body === null) {
    return undefined;
  }

  const envelope = body as Record<string, unknown>;
  const data =
    typeof envelope.data === 'object' && envelope.data !== null
      ? (envelope.data as Record<string, unknown>)
      : undefined;

  const candidate =
    data?.name ??
    data?.username ??
    data?.user ??
    envelope.name ??
    envelope.username ??
    envelope.user;

  return typeof candidate === 'string' ? candidate : undefined;
}
