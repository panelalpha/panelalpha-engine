import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';

/**
 * The engine's `/api/internal/` routes are for the webserver on this host. The engine
 * port refuses them in nginx, with or without the front controller in the path.
 */
test.describe('Internal routes', () => {
  const paths = ['/api/internal/site-password', '/index.php/api/internal/site-password'];

  for (const prefix of paths) {
    test(`${prefix} is not served on the engine port`, async ({ authedRequest, settings }) => {
      const origin = new URL(settings.apiBaseUrl).origin;

      for (const action of ['check', 'gate', 'login']) {
        await test.step(action, async () => {
          const response = await authedRequest.get(`${origin}${prefix}/api-test-user/${action}`, {
            maxRedirects: 0,
          });
          await expectStatus(response, 404, `${prefix}/api-test-user/${action}`);
          // Laravel would answer check with 200 and a GET login with 405; this body is nginx's.
          expect(await response.json()).toEqual({ message: 'Not found' });
        });
      }
    });
  }
});
