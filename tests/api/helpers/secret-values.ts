export const REDACTED = '[REDACTED]';

/** Secrets shorter than this are not worth a rewrite and could match by chance. */
const MIN_SECRET_LENGTH = 8;

export function usableSecrets(values: readonly (string | undefined | null)[]): string[] {
  return [
    ...new Set(values.map((v) => v?.trim() ?? '').filter((v) => v.length >= MIN_SECRET_LENGTH)),
  ];
}
