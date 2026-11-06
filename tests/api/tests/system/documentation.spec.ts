import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';

const DOC_PATHS = [
  'documentation',
  'documentation/openapi.json',
  'documentation/openapi.json/asset/swagger-ui.css',
];

/** The document and viewer answer only with SWAGGER_ENABLED; the OAuth callback always does. */
test.describe('API documentation', () => {
  test('the document and viewer are served whole or not at all', async ({ anonymousRequest }) => {
    const document = await anonymousRequest.get('documentation/openapi.json');

    if (document.status() === 404) {
      test.info().annotations.push({ type: 'swagger', description: 'SWAGGER_ENABLED is off' });
      for (const path of DOC_PATHS) {
        const response = await anonymousRequest.get(path);
        await expectStatus(response, 404, path);
        expect(await response.json(), path).toEqual({ message: 'Not found' });
      }
      return;
    }

    await expectStatus(document, 200);
    const spec = (await document.json()) as { openapi?: string; paths?: Record<string, unknown> };
    expect(spec.openapi).toMatch(/^3\./);
    expect(Object.keys(spec.paths ?? {})).toEqual(
      expect.arrayContaining(['/projects', '/projects/{username}'])
    );
    const viewer = await anonymousRequest.get('documentation');
    await expectStatus(viewer, 200);
    expect(await viewer.text()).toContain('swagger-ui');
  });

  test('the OAuth callback page is served without a token', async ({ anonymousRequest }) => {
    const response = await anonymousRequest.get('oauth2-callback');
    await expectStatus(response, 200);
    expect(response.headers()['content-type']).toContain('text/html');
    expect(await response.text()).toContain('oauth2-redirect.js');
  });
});
