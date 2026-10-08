import { expect, test } from '@/fixtures/test-options';

test.describe('health endpoints', () => {
  test('test-connection reports success', { tag: ['@smoke'] }, async ({ api, authedRequest }) => {
    expect(await api.testConnection()).toEqual({ success: true });

    const raw = await authedRequest.get('test-connection');
    expect(raw.ok()).toBe(true);
    expect(((await raw.json()) as { success?: boolean }).success).toBe(true);
  });

  test('root health check answers 204', { tag: ['@smoke'] }, async ({ api }) => {
    const { status } = await api.healthCheck();
    expect(status).toBe(204);
  });

  test('system info returns a payload', async ({ api }) => {
    expect((await api.getSystemInfo()).data).toBeDefined();
  });
});
