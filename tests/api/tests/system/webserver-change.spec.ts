import { expect, test } from '@/fixtures/test-options';
import { changeWebserverAndWait } from '@/helpers/webserver-change';
import {
  VALID_SLUGS,
  getWebserverInfo,
  switchingDisabledReason,
  type WebserverSlug,
} from '@/helpers/webserver-helpers';

/**
 * Switches the engine to the webserver named in `WEBSERVER` and waits until it
 * actually serves traffic.
 *
 * This reconfigures the host underneath everything else and takes tens of
 * minutes, so it has its own project and never runs as part of `npm test`:
 *
 *     WEBSERVER=nginx npm run test:webserver-change
 */
test('the engine changes to the webserver named in WEBSERVER', async ({
  api,
  anonymousRequest,
  settings,
  setupUser,
}) => {
  const target = process.env.WEBSERVER?.trim().toLowerCase() ?? '';

  expect(target, 'WEBSERVER is not set — there is no target to change to.').toBeTruthy();
  expect(VALID_SLUGS, `WEBSERVER="${target}" is not one of the supported webservers.`).toContain(
    target
  );

  test.skip(
    target === 'litespeed' && !process.env.LITESPEED_SERIAL_NUMBER?.trim(),
    'LiteSpeed Enterprise cannot start without LITESPEED_SERIAL_NUMBER.'
  );

  try {
    await changeWebserverAndWait({
      api,
      http: anonymousRequest,
      target: target as WebserverSlug,
      setupUser,
      timeoutMs: settings.timing.webserverChangeTimeout,
    });
  } catch (error) {
    // The engine's 422 for a switch it refuses on purpose, as webserver.spec.ts skips it.
    const disabled = switchingDisabledReason(
      error instanceof Error ? error.message : String(error)
    );
    test.skip(disabled !== null, disabled ?? '');
    throw error;
  }

  // changeWebserverAndWait already gated on the site serving; this records what
  // /system/info believes, which can legitimately lag behind on a stale cache.
  const { slug } = await getWebserverInfo(api);
  test.info().annotations.push({ type: 'webserver', description: `${slug} (target ${target})` });
});
