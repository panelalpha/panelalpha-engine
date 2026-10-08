import { expect, test } from '@/fixtures/test-options';
import { SystemApi } from '@/clients/resources/system.api';
import type { ApiTransport } from '@/clients/api-transport';
import type { APIResponse } from '@/fixtures/test-options';

/**
 * Pure unit coverage of the change-webserver payload — runs against a stub
 * transport, so it needs no engine and belongs to the `unit` project.
 */

function response(status: number, body: string): APIResponse {
  return {
    status: () => status,
    ok: () => status >= 200 && status < 300,
    url: () => 'https://engine.test/api/system/change-webserver',
    text: () => Promise.resolve(body),
    json: () => Promise.resolve(JSON.parse(body) as unknown),
  } as unknown as APIResponse;
}

function stubTransport(onPut?: (url: string, options?: { data?: unknown }) => void): ApiTransport {
  return {
    put: (url, options) => {
      onPut?.(url, options);
      return Promise.resolve(response(200, '{"data":{}}'));
    },
    get: () => Promise.resolve(response(200, '{}')),
    post: () => Promise.resolve(response(200, '{}')),
    delete: () => Promise.resolve(response(200, '{}')),
  };
}

/** Runs `changeWebserver` against a stub and returns the body it would have sent. */
async function capturePayload(...args: Parameters<SystemApi['changeWebserver']>): Promise<unknown> {
  let captured: unknown;
  const api = new SystemApi(stubTransport((_url, options) => (captured = options?.data)));
  await api.changeWebserver(...args);
  return captured;
}

test.describe('SystemApi.changeWebserver', () => {
  test('reports the status and body when the engine rejects the change', async () => {
    const api = new SystemApi({
      ...stubTransport(),
      put: () => Promise.resolve(response(422, '{"message":"already changing"}')),
    });

    await expect(api.changeWebserver('litespeed')).rejects.toThrow(
      /Expected: 200 \| 202\s+—\s+Got: 422/
    );
  });

  test('sends the serial number when one is provided', async () => {
    await expect(capturePayload('litespeed', { serial_number: 'TEST-SERIAL' })).resolves.toEqual({
      new_webserver: 'litespeed',
      serial_number: 'TEST-SERIAL',
    });
  });

  test('trims surrounding whitespace from the serial number', async () => {
    await expect(
      capturePayload('litespeed', { serial_number: '  TEST-SERIAL  ' })
    ).resolves.toEqual({
      new_webserver: 'litespeed',
      serial_number: 'TEST-SERIAL',
    });
  });

  test('omits the serial number when none is provided', async () => {
    await expect(capturePayload('litespeed')).resolves.toEqual({ new_webserver: 'litespeed' });
  });
});

test.describe('SystemApi.updateSystemNetworkConfig', () => {
  test('notes the attempts and reasons when the engine is restarting a container', async () => {
    const answers = [
      response(422, '{"message":"Wait until the container is running"}'),
      response(200, '{"data":{}}'),
    ];
    const api = new SystemApi({
      ...stubTransport(),
      put: () => Promise.resolve(answers.shift()!),
    });

    await api.updateSystemNetworkConfig({ default_ipv4: '10.0.0.1' });
    const notes = test
      .info()
      .annotations.filter((a) => a.type === 'retry')
      .map((a) => a.description);
    expect(notes).toEqual([
      'PUT system/network-config: 2 attempts; reasons: HTTP 422 Wait until the container is running',
    ]);
  });
});
