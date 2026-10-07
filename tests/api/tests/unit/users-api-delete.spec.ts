import { expect, test } from '@/fixtures/test-options';
import { UsersApi } from '@/clients/resources/users.api';
import type { ApiTransport } from '@/clients/api-transport';
import type { APIResponse } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';

/**
 * The project delete every cleanup goes through, against a stub transport: the
 * engine's 409 while a job works on the project, and a project left behind.
 */

function response(status: number, body: unknown = {}): APIResponse {
  const text = JSON.stringify(body);
  return {
    status: () => status,
    ok: () => status >= 200 && status < 300,
    url: () => 'https://engine.test/api/projects/pwtest',
    text: () => Promise.resolve(text),
    json: () => Promise.resolve(JSON.parse(text) as unknown),
  } as unknown as APIResponse;
}

/** The engine's own wording (ProjectBusyException). */
const running = response(409, {
  message:
    "Project 'pwtest' is busy: deploy task 507 is running. Cancel it (POST /tasks/507/cancel) or wait for it to finish.",
});
const stopping = response(409, {
  message:
    "Project 'pwtest' is busy: deploy task 507 was cancelled and is still stopping. Wait for it to finish.",
});

/** Answers each DELETE with the next response in line, and records every call. */
function engine(deletes: APIResponse[], shown = response(404)): { api: UsersApi; calls: string[] } {
  const calls: string[] = [];
  const transport: ApiTransport = {
    delete: (url) => {
      calls.push(`DELETE ${url}`);
      return Promise.resolve(deletes.shift() ?? response(404));
    },
    post: (url) => {
      calls.push(`POST ${url}`);
      return Promise.resolve(response(200, { data: { cancelled: true } }));
    },
    get: (url) => {
      calls.push(`GET ${url}`);
      return Promise.resolve(shown);
    },
    put: () => Promise.reject(new Error('unexpected PUT')),
  };
  return { api: new UsersApi(transport), calls };
}

test.describe('UsersApi project delete', () => {
  test('a 409 naming a running task cancels it and deletes again', async () => {
    const { api, calls } = engine([running, response(200)]);

    await expect(api.deleteUserSafe('pwtest')).resolves.toBe(200);
    expect(calls).toEqual([
      'DELETE projects/pwtest',
      'POST tasks/507/cancel',
      'DELETE projects/pwtest',
    ]);
  });

  test('a cancelled task still stopping is waited out, not cancelled again', async () => {
    const { api, calls } = engine([running, stopping, response(200)]);

    await api.deleteUser('pwtest');
    expect(calls).toEqual([
      'DELETE projects/pwtest',
      'POST tasks/507/cancel',
      'DELETE projects/pwtest',
      'DELETE projects/pwtest',
    ]);
  });

  test('a 409 lengthens the running test by the retry budget', async () => {
    const { api } = engine([running, response(200)]);
    const before = test.info().timeout;

    await api.deleteUserSafe('pwtest');
    expect(test.info().timeout).toBe(before + Timeouts.projectDelete + 15_000);
  });

  test('a delete that succeeds at once leaves the test timeout alone', async () => {
    const { api } = engine([response(200)]);
    const before = test.info().timeout;

    await api.deleteUserSafe('pwtest');
    expect(test.info().timeout).toBe(before);
  });

  test('a project that is already gone is not an error', async () => {
    const { api } = engine([response(404)]);

    await expect(api.deleteUserSafe('pwtest')).resolves.toBe(404);
  });

  test('a project still on the engine afterwards fails the cleanup', async () => {
    const { api } = engine([response(500, { message: 'Server Error' })], response(200));

    await expect(api.deleteUserSafe('pwtest')).rejects.toThrow(
      /Project pwtest is still on the engine: DELETE answered 500/
    );
  });
});
