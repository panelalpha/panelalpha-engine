import { expect, test } from '@/fixtures/test-options';
import { createApiTransport } from '@/clients/api-transport';
import { EngineApi } from '@/clients/engine-api';
import { expectStatus } from '@/helpers/expect-status';
import { skipUnless } from '@/helpers/test-helpers';

/** Recipe app credentials and the one-shot SSO link, on a project deployed without a recipe. */
test.describe('app credentials and SSO link', () => {
  test('a project without recipe credentials says none are available', async ({
    api,
    sharedEmptyDind,
  }) => {
    const project = await sharedEmptyDind.get();
    skipUnless(project, 'DinD is not available on this engine.');
    const response = await api.getAppCredentialsRaw(project.username);
    await expectStatus(response, 200, 'GET app-credentials');
    expect(response.body).toEqual({
      data: { available: false, login_url: null, created_at: null, fields: [] },
    });
  });

  test('the credentials of a project that does not exist are 404', async ({ api }) => {
    await expectStatus(await api.getAppCredentialsRaw('nosuchproject999'), 404);
  });

  test('the SSO link needs no API token but refuses a token it never issued', async ({
    anonymousRequest,
    sharedEmptyDind,
  }) => {
    const project = await sharedEmptyDind.get();
    skipUnless(project, 'DinD is not available on this engine.');
    const anonymous = new EngineApi(createApiTransport(anonymousRequest));

    for (const token of ['', 'never-issued', 'a'.repeat(64)]) {
      const response = await anonymous.getAppSsoTokenRaw(project.username, token);
      await expectStatus(response, 404, `token ${JSON.stringify(token)}`);
      expect(response.headers['set-cookie'], 'a refused token sets no cookie').toBeUndefined();
    }
  });
});
