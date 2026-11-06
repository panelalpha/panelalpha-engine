import { rmSync } from 'node:fs';

import { expect, test } from '@/fixtures/test-options';
import type { EngineApi } from '@/clients/engine-api';
import { resourceFile } from '@/helpers/resource-registry';
import { UserFactory } from '@/test-data/factories/user.factory';

/** The domain cleanup a delete runs first, against an Engine answering `status` for the list. */

interface Raw {
  status: number;
  body: unknown;
}

function fakeApi(status: number): { api: EngineApi; deletedDomains: string[] } {
  const deletedDomains: string[] = [];
  const body =
    status === 200
      ? {
          data: [
            { domain: 'main.test', type: 'main' },
            { domain: 'addon.test', type: 'addon' },
            { domain: 'alias.test', type: 'alias' },
          ],
        }
      : { message: status === 404 ? 'Project not found' : 'Server Error' };
  const api = {
    listUserDomainsRaw: () => Promise.resolve<Raw>({ status, body }),
    deleteDomain: (_username: string, domain: string) => {
      deletedDomains.push(domain);
      return Promise.resolve();
    },
    deleteUserSafe: () => Promise.resolve(status === 404 ? 404 : 200),
  };
  return { api: api as unknown as EngineApi, deletedDomains };
}

test.describe('UserFactory domain cleanup', () => {
  let runId: string;
  let warnings: string[];
  let restoreWarn: () => void;

  test.beforeEach(() => {
    runId = `unit-${Math.random().toString(16).slice(2, 10)}`;
    process.env.TEST_RUN_ID = runId;
    process.env.API_BASE_URL ??= 'https://engine.test/api';
    warnings = [];
    const original = console.warn;
    console.warn = (...args: unknown[]) => {
      warnings.push(args.map(String).join(' '));
    };
    restoreWarn = () => {
      console.warn = original;
    };
  });
  test.afterEach(() => {
    restoreWarn();
    rmSync(resourceFile(runId), { force: true });
    delete process.env.TEST_RUN_ID;
  });

  test('a project that is already gone is cleaned up without a warning', async () => {
    const { api } = fakeApi(404);

    await new UserFactory(api).deleteUser('pwgone0001');

    expect(warnings).toEqual([]);
  });

  test('a domain list the Engine fails to answer is still a warning', async () => {
    const { api } = fakeApi(500);

    await new UserFactory(api).deleteUser('pwfail0001');

    expect(warnings).toHaveLength(1);
    expect(warnings[0]).toContain('Failed to cleanup domains for pwfail0001');
    expect(warnings[0]).toContain('500');
  });

  test('addon and alias domains are deleted, the main domain is left to the project delete', async () => {
    const { api, deletedDomains } = fakeApi(200);

    await new UserFactory(api).deleteUser('pwlive0001');

    expect(deletedDomains).toEqual(['addon.test', 'alias.test']);
    expect(warnings).toEqual([]);
  });
});
