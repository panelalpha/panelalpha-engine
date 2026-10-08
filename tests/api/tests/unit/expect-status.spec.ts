import { expect, test } from '@/fixtures/test-options';
import type { APIRequestContext, APIResponse } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { rememberRequest, requestMethodOf, withRequestMethods } from '@/helpers/request-method';

/**
 * The status assertion every spec uses: what its failure message shows, what it hides,
 * and the method registry that lets it name the request.
 */

function response(
  status: number,
  body: string,
  options: { url?: string; headers?: Record<string, string> } = {}
): APIResponse {
  return {
    status: () => status,
    url: () => options.url ?? 'https://engine.test/api/projects/pwab/domains',
    headers: () => options.headers ?? {},
    text: () => Promise.resolve(body),
  } as unknown as APIResponse;
}

async function failure(run: () => Promise<void>): Promise<string> {
  try {
    await run();
  } catch (error) {
    return error instanceof Error ? error.message : String(error);
  }
  throw new Error('expected the assertion to fail');
}

test.describe('expectStatus', () => {
  test('a matching status does not throw or read the body', async () => {
    let reads = 0;
    const ok = {
      ...response(200, ''),
      text: () => {
        reads += 1;
        return Promise.resolve('');
      },
    };
    await expectStatus(ok, 200);
    await expectStatus(ok, [200, 201]);
    await expectStatus({ status: 204, body: null }, 204);
    expect(reads).toBe(0);
  });

  test('a mismatch names the method, URL, statuses and body', async () => {
    const res = rememberRequest(response(500, '{"message":"Server Error"}'), 'post');
    const message = await failure(() => expectStatus(res, 422, 'creating a domain'));
    expect(message).toBe(
      [
        'creating a domain',
        'Expected status 422, got 500',
        '  Request:  POST https://engine.test/api/projects/pwab/domains',
        '  Response: {"message":"Server Error"}',
      ].join('\n')
    );
  });

  test('an unrecorded method is marked unknown', async () => {
    const message = await failure(() => expectStatus(response(404, 'nope'), 200));
    expect(message).toContain('Request:  <method unknown> https://');
  });

  test('the request id shows only when the engine sent one', async () => {
    const without = await failure(() => expectStatus(response(500, 'x'), 200));
    expect(without).not.toContain('Request-Id');
    const withId = await failure(() =>
      expectStatus(response(500, 'x', { headers: { 'x-request-id': 'abc123' } }), 200)
    );
    expect(withId).toContain('  Request-Id: abc123');
  });

  test('an array of allowed statuses is listed', async () => {
    const message = await failure(() => expectStatus(response(500, 'x'), [200, 201]));
    expect(message).toContain('Expected status one of 200 | 201, got 500');
    await expectStatus(response(201, 'x'), [200, 201]);
  });

  test('secrets in the URL and body are redacted', async () => {
    const previous = process.env.API_TOKEN;
    process.env.API_TOKEN = 'planted-token-value-123';
    try {
      const res = response(500, '{"password":"x","note":"planted-token-value-123"}', {
        url: 'https://user:hunter2pass@engine.test/api/x?token=abcdef&page=2',
      });
      const message = await failure(() => expectStatus(res, 200));
      expect(message).not.toContain('planted-token-value-123');
      expect(message).not.toContain('hunter2pass');
      expect(message).not.toContain('abcdef');
      expect(message).not.toContain('"password":"x"');
      expect(message).toContain('page=2');
    } finally {
      if (previous === undefined) {
        delete process.env.API_TOKEN;
      } else {
        process.env.API_TOKEN = previous;
      }
    }
  });

  test('a long body is cut at the head with a marker', async () => {
    const body = `START${'a'.repeat(3000)}END`;
    const message = await failure(() => expectStatus(response(500, body), 200));
    expect(message).toContain('START');
    expect(message).not.toContain('END');
    expect(message).toMatch(/…\[1008 chars cut\]/);
  });

  test('a raw response shows only the redacted JSON body', async () => {
    const message = await failure(() =>
      expectStatus({ status: 500, body: { message: 'boom', password: 'secret1' } }, 200, 'raw')
    );
    expect(message).toMatch(
      /^raw\nExpected status 200, got 500\n {2}Response: \{"message":"boom","password":".*"\}$/
    );
    expect(message).not.toContain('secret1');
    expect(message).not.toContain('Request:');
  });

  test('a data-shaped result shows its data as the redacted JSON body', async () => {
    const message = await failure(() =>
      expectStatus({ status: 500, data: { message: 'boom', password: 'secret2' } }, 200)
    );
    expect(message).toContain('Expected status 200, got 500');
    expect(message).toContain('  Response: {"message":"boom","password":"');
    expect(message).not.toContain('secret2');
    expect(message).not.toContain('Request:');
  });
});

test.describe('withRequestMethods', () => {
  function fakeContext(): { context: APIRequestContext; disposed: () => boolean } {
    let disposed = false;
    const reply = (): Promise<APIResponse> => Promise.resolve(response(200, ''));
    const fake = {
      get: reply,
      post: reply,
      put: reply,
      patch: reply,
      delete: reply,
      head: reply,
      fetch: reply,
      dispose: () => {
        disposed = true;
        return Promise.resolve();
      },
    };
    return { context: fake as unknown as APIRequestContext, disposed: () => disposed };
  }

  test('every verb records its method on the response', async () => {
    const { context } = fakeContext();
    const wrapped = withRequestMethods(context);
    expect(requestMethodOf(await wrapped.get('a'))).toBe('GET');
    expect(requestMethodOf(await wrapped.post('a'))).toBe('POST');
    expect(requestMethodOf(await wrapped.put('a'))).toBe('PUT');
    expect(requestMethodOf(await wrapped.patch('a'))).toBe('PATCH');
    expect(requestMethodOf(await wrapped.delete('a'))).toBe('DELETE');
    expect(requestMethodOf(await wrapped.head('a'))).toBe('HEAD');
  });

  test('fetch uses options.method and defaults to GET', async () => {
    const wrapped = withRequestMethods(fakeContext().context);
    expect(requestMethodOf(await wrapped.fetch('a', { method: 'put' }))).toBe('PUT');
    expect(requestMethodOf(await wrapped.fetch('a'))).toBe('GET');
  });

  test('other members pass through to the real context', async () => {
    const { context, disposed } = fakeContext();
    await withRequestMethods(context).dispose();
    expect(disposed()).toBe(true);
  });
});
