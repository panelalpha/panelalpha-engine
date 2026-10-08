import { rmSync } from 'node:fs';

import { expect, test } from '@/fixtures/test-options';
import type { EngineApi } from '@/clients/engine-api';
import { readResourceEvents, resourceFile } from '@/helpers/resource-registry';
import { manualCleanupPending, UserFactory } from '@/test-data/factories/user.factory';

const TOKEN = 'tok_PLANTEDLIFECYCLETOKEN1';

interface Raw {
  status: number;
  body: unknown;
}

interface FakeOptions {
  createFails?: boolean;
  createRefused?: boolean;
  deleteFails?: boolean;
}

/** An Engine that records what it was asked, in order, and fails on request. */
function fakeApi(runId: string, options: FakeOptions = {}) {
  const calls: string[] = [];
  const eventsAt = (): string[] => readResourceEvents(resourceFile(runId)).map((e) => e.event);
  const api = {
    createUser: () => {
      calls.push(`POST seen=${eventsAt().join(',')}`);
      return options.createFails
        ? Promise.reject(new Error('socket hang up'))
        : Promise.resolve({ data: { domain: 'x.test' } });
    },
    createUserRaw: () => {
      calls.push(`POST seen=${eventsAt().join(',')}`);
      if (options.createFails) {
        return Promise.reject(new Error('socket hang up'));
      }
      return Promise.resolve<Raw>(
        options.createRefused
          ? {
              status: 422,
              body: { problems: [{ field: 'template', code: 'template_not_found' }] },
            }
          : { status: 201, body: {} }
      );
    },
    getUser: () => Promise.resolve({ data: { domain: 'x.test' } }),
    listUserDomains: () => Promise.resolve({ data: [] }),
    deleteUserSafe: (username: string) => {
      calls.push(`DELETE ${username}`);
      return options.deleteFails
        ? Promise.reject(new Error('Project is still on the engine: DELETE answered 500'))
        : Promise.resolve(200);
    },
    getUserRaw: () => Promise.resolve<Raw>({ status: 200, body: { data: { username: 'u' } } }),
    getDeployLogRaw: () =>
      Promise.resolve<Raw>({
        status: 200,
        body: { data: { lines: ['one', `token ${TOKEN}`], status: 'success' } },
      }),
  };
  return { api: api as unknown as EngineApi, calls, eventsAt };
}

test.describe('UserFactory lifecycle', () => {
  let runId: string;
  test.beforeEach(() => {
    runId = `unit-${Math.random().toString(16).slice(2, 10)}`;
    process.env.TEST_RUN_ID = runId;
    process.env.API_TOKEN = TOKEN;
    process.env.API_BASE_URL ??= 'https://engine.test/api';
  });
  test.afterEach(() => {
    rmSync(resourceFile(runId), { force: true });
    delete process.env.TEST_RUN_ID;
    delete process.env.API_TOKEN;
  });

  test('records creating before the POST and created after it', async () => {
    const { api, calls, eventsAt } = fakeApi(runId);
    const factory = new UserFactory(api);
    const { username } = await factory.createSimpleUser();

    expect(calls[0]).toBe('POST seen=creating');
    expect(eventsAt()).toEqual(['creating', 'created']);
    expect(factory.pendingCleanup).toEqual([username]);
  });

  test('a create that throws stays tracked and teardown deletes it', async () => {
    const { api, calls, eventsAt } = fakeApi(runId, { createFails: true });
    const factory = new UserFactory(api);
    await expect(factory.createSimpleUser()).rejects.toThrow('socket hang up');

    expect(factory.pendingCleanup).toHaveLength(1);
    expect(eventsAt()).toEqual(['creating', 'create-failed']);

    const { errors } = await factory.settle(true, {});
    expect(errors).toEqual([]);
    expect(calls.filter((c) => c.startsWith('DELETE'))).toHaveLength(1);
    expect(factory.pendingCleanup).toEqual([]);
    expect(eventsAt()).toEqual(['creating', 'create-failed', 'deleting', 'deleted']);
  });

  test('a failed delete keeps the user tracked and is recorded', async () => {
    const { api, eventsAt } = fakeApi(runId, { deleteFails: true });
    const factory = new UserFactory(api);
    const { username } = await factory.createSimpleUser();

    await expect(factory.deleteUser(username)).rejects.toThrow('still on the engine');
    expect(factory.pendingCleanup).toEqual([username]);
    expect(eventsAt()).toEqual(['creating', 'created', 'deleting', 'delete-failed']);

    const { errors } = await factory.settle(false, {});
    expect(errors).toHaveLength(1);
    expect(factory.pendingCleanup).toEqual([username]);
  });

  test('a refused create is recorded and untracked', async () => {
    const { api, eventsAt } = fakeApi(runId, { createRefused: true });
    const factory = new UserFactory(api);

    expect(await factory.createDindUser()).toBeNull();
    expect(factory.pendingCleanup).toEqual([]);
    expect(eventsAt()).toEqual(['creating', 'refused']);
  });

  const matrix: {
    failed: boolean;
    env: NodeJS.ProcessEnv;
    option?: boolean;
    keeps: boolean;
  }[] = [
    { failed: false, env: {}, keeps: false },
    { failed: false, env: { PRESERVE_ON_FAILURE: '1' }, keeps: false },
    { failed: true, env: {}, keeps: false },
    { failed: true, env: { PRESERVE_ON_FAILURE: '1' }, keeps: true },
    { failed: true, env: { PRESERVE_ON_FAILURE: '1', CI: 'true' }, keeps: false },
    { failed: true, env: { CI: 'true' }, keeps: false },
    { failed: true, env: { PRESERVE_ON_FAILURE: '1' }, option: false, keeps: false },
  ];
  for (const row of matrix) {
    const name = `${row.failed ? 'failed' : 'passed'} ${JSON.stringify(row.env)}${
      row.option === false ? ' preserveOnFailure:false' : ''
    } ${row.keeps ? 'keeps' : 'deletes'}`;
    test(`preserve policy: ${name}`, async () => {
      const { api, calls, eventsAt } = fakeApi(runId);
      const factory = new UserFactory(api);
      const { username } = await factory.createSimpleUser(
        row.option === false ? { preserveOnFailure: false } : {}
      );

      const { kept, errors } = await factory.settle(row.failed, row.env);

      expect(errors).toEqual([]);
      expect(kept).toEqual(row.keeps ? [username] : []);
      expect(calls.some((c) => c.startsWith('DELETE'))).toBe(!row.keeps);
      expect(eventsAt().includes('preserved')).toBe(row.keeps);
    });
  }

  test('a failed test has its project and deploy log attached before deletion', async () => {
    const { api, calls } = fakeApi(runId);
    const factory = new UserFactory(api);
    const { username } = await factory.createSimpleUser();

    await factory.attachFailureDiagnostics(username);
    const attachment = test.info().attachments.find((a) => a.name.includes('diagnostics'));
    const text = attachment?.body?.toString() ?? '';
    expect(text).toContain(`GET projects/${username} -> 200`);
    expect(text).toContain('deploy log');
    expect(text).not.toContain(TOKEN);
    expect(calls.some((c) => c.startsWith('DELETE'))).toBe(false);
  });

  test('autoCleanup:false users are listed for the worker until the test deletes them', async () => {
    const { api, eventsAt } = fakeApi(runId);
    const factory = new UserFactory(api);
    const kept = await factory.createSimpleUser({ autoCleanup: false });
    const left = await factory.createSimpleUser({ autoCleanup: false });

    expect(factory.pendingCleanup).toEqual([]);
    expect(manualCleanupPending()).toEqual(expect.arrayContaining([kept.username, left.username]));

    await factory.deleteUser(kept.username);
    expect(manualCleanupPending()).toEqual([left.username]);

    // What the worker fixture does at shutdown.
    for (const username of manualCleanupPending()) {
      await new UserFactory(api).deleteUser(username);
    }
    expect(manualCleanupPending()).toEqual([]);
    expect(eventsAt().filter((e) => e === 'deleted')).toHaveLength(2);
  });

  test('a throwing autoCleanup:false create is still listed for the worker', async () => {
    const { api } = fakeApi(runId, { createFails: true });
    const factory = new UserFactory(api);
    await expect(factory.createSimpleUser({ autoCleanup: false })).rejects.toThrow();

    const [username] = manualCleanupPending();
    expect(username).toBeDefined();
    await new UserFactory(fakeApi(runId).api).deleteUser(username);
    expect(manualCleanupPending()).toEqual([]);
  });
});
