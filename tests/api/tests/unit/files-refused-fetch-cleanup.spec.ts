import { readFileSync } from 'node:fs';
import { expect, test } from '@/fixtures/test-options';
import type { EngineApi } from '@/clients/engine-api';
import { removeFileIfPresent } from '@/helpers/file-path-helpers';

const SPEC = new URL('../files/files-move-fetch-chmod.spec.ts', import.meta.url);

test.describe('files refused-fetch cleanup', () => {
  test('removeFileIfPresent skips a path that was never created', async () => {
    const calls: string[] = [];
    const api = {
      fileExists: () => Promise.resolve({ exists: false, path: '/x' }),
      removeFile: () => {
        calls.push('removeFile');
        return Promise.reject(new Error('remove answered 404'));
      },
    } as unknown as EngineApi;

    await removeFileIfPresent(api, 'alice', '/x');

    expect(calls).toEqual([]);
  });

  test('removeFileIfPresent removes a path that is there', async () => {
    const calls: string[] = [];
    const api = {
      fileExists: () => Promise.resolve({ exists: true, path: '/x' }),
      removeFile: (_username: string, path: string, recursive = false) => {
        calls.push(`remove ${path} recursive=${recursive}`);
        return Promise.resolve();
      },
    } as unknown as EngineApi;

    await removeFileIfPresent(api, 'alice', '/x');

    expect(calls).toEqual(['remove /x recursive=false']);
  });

  test('the refused-fetch test cleans up conditionally', () => {
    const source = readFileSync(SPEC, 'utf8');
    const start = source.indexOf("test('fetch refuses an internal address");
    expect(start).toBeGreaterThan(-1);

    const refusedFetch = source.slice(start);
    expect(refusedFetch).toContain('removeFileIfPresent');
    expect(refusedFetch).not.toContain('api.removeFile(');
  });
});
