import { expect } from '@playwright/test';
import type { EngineApi } from '@/clients/engine-api';

export function getDomainBasePath(domain: string): string {
  return `/${domain}/public_html`;
}

/** Compares downloaded text to what was written, ignoring trailing whitespace. */
export function expectFileContentEquals(actual: string, expected: string): void {
  const got = actual.trim();
  const want = expected.trim();
  expect(got, `file content mismatch (got ${got.length} chars, expected ${want.length})`).toBe(
    want
  );
}

export function randomFileName(extension: string): string {
  return `testfile_${Date.now()}_${Math.random().toString(36).slice(2)}.${extension}`;
}

export function randomFileContent(): string {
  return `Test content ${Date.now()} ${Math.random().toString(36).slice(2)}`;
}

/**
 * Removes a path only when it is still there, so a path the test already removed
 * (or never reached) is not reported as a failed cleanup.
 */
export async function removeFileIfPresent(
  api: EngineApi,
  username: string,
  path: string,
  recursive = false
): Promise<void> {
  if ((await api.fileExists(username, path)).exists) {
    await api.removeFile(username, path, recursive);
  }
}
