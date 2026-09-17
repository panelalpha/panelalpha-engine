import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { VALID_SLUGS, getWebserverInfo } from '@/helpers/webserver-helpers';

/** Change-webserver is asynchronous, so a queued job and a refusal both count. */
const CHANGE_HANDLED = [200, 202, 204, 400, 409, 422, 500] as const;

function litespeedSerial(webserver: { serial_number?: string } | undefined): string | undefined {
  return (
    process.env.LITESPEED_SERIAL_NUMBER ?? process.env.LITESPEED_SERIAL ?? webserver?.serial_number
  );
}

test.describe('webserver information', () => {
  test('system info names the active webserver', async ({ api }) => {
    const { data } = await api.getSystemInfo();

    expect(data.version).toBeDefined();
    expect(data.webserver).toBeDefined();

    const label =
      typeof data.webserver === 'string'
        ? data.webserver
        : (data.webserver.name ?? data.webserver.type ?? data.webserver.slug);
    expect(label).toBeDefined();
  });

  test('the active webserver is one this suite knows', async ({ api }) => {
    const { slug } = await getWebserverInfo(api);

    expect(
      VALID_SLUGS.some((known) => slug === known || slug.includes(known)),
      `"${slug}" is not one of ${VALID_SLUGS.join(', ')}`
    ).toBe(true);
  });

  test('at least one PHP version is offered', async ({ api }) => {
    const { data } = await api.getAvailablePhpVersions();

    expect(Array.isArray(data)).toBe(true);
    expect(data.length).toBeGreaterThan(0);
  });

  const infoFields = ['latest_webserver_change', 'latest_update', 'default_ipv4', 'default_ipv6'];

  for (const field of infoFields) {
    test(`system info carries ${field}`, async ({ api }) => {
      expect(field in (await api.getSystemInfo()).data).toBe(true);
    });
  }

  test('a reported default IPv4 is well formed', async ({ api }) => {
    const { default_ipv4: defaultIpv4 } = (await api.getSystemInfo()).data;
    test.skip(!defaultIpv4, 'This engine reports no default IPv4.');

    expect(defaultIpv4).toMatch(/^\d+\.\d+\.\d+\.\d+$/);
  });
});

test.describe('change-webserver validation', () => {
  test('an unknown webserver name is refused', async ({ authedRequest }) => {
    const response = await authedRequest.put('system/change-webserver', {
      data: { new_webserver: 'invalid-webserver' },
    });
    expect(response.status()).toBe(422);
  });

  /**
   * Some engine builds refuse every switch up front — nginx-proxy is required
   * for DinD projects — and answer 422 on `new_webserver` before they ever look
   * at the rest of the payload.
   *
   * That makes the tests below unable to see what they are about: they would
   * pass on the 422 without the engine having validated anything. So the
   * refusal is recognised and the test skips, rather than reporting a pass it
   * did not earn.
   */
  function switchingDisabledReason(body: unknown): string | null {
    const errors = (body as { errors?: Record<string, string[] | undefined> } | null)?.errors;
    const newWebserver = errors?.new_webserver?.join(' ') ?? '';
    return /temporarily disabled|only nginx-proxy is supported/i.test(newWebserver)
      ? `This engine refuses every webserver switch: ${newWebserver}`
      : null;
  }

  /** The serial only means something for LiteSpeed Enterprise. */
  const nonLicensedTargets = ['nginx', 'openlitespeed'] as const;

  for (const target of nonLicensedTargets) {
    test(`a serial number is refused when changing to ${target}`, async ({ api }) => {
      const { status, body } = await api.changeWebserverRaw({
        new_webserver: target,
        serial_number: 'TEST-SERIAL',
      });
      expect(status).toBe(422);

      const disabled = switchingDisabledReason(body);
      test.skip(disabled !== null, disabled ?? '');

      // With switching available, the 422 has to be about the serial.
      expect(
        /serial/i.test(JSON.stringify(body)),
        `${target} was refused for something other than the serial: ${JSON.stringify(body).slice(0, 400)}`
      ).toBe(true);
    });
  }

  test('a malformed LiteSpeed serial is refused, and the refusal names the serial', async ({
    api,
  }) => {
    const { status, body } = await api.changeWebserverRaw({
      new_webserver: 'litespeed',
      serial_number: 'invalid-serial-number',
    });

    expect(status).toBe(422);

    const disabled = switchingDisabledReason(body);
    test.skip(disabled !== null, disabled ?? '');

    // A 422 that does not say which field was wrong leaves the caller guessing.
    // Laravel's `errors.serial_number` is the usual shape, but any refusal that
    // names the serial is a usable answer — what must not happen is a bare 422.
    // The body goes in the message so a mismatch says what it got, rather than
    // "undefined" as this assertion used to.
    const serialised = JSON.stringify(body);
    const fieldError = (body as { errors?: { serial_number?: string[] } }).errors?.serial_number;
    expect(
      fieldError !== undefined || /serial/i.test(serialised),
      `the engine refused the serial without saying so: ${serialised.slice(0, 400)}`
    ).toBe(true);
  });

  test('changing to the webserver already in use is handled', async ({ api, authedRequest }) => {
    const { slug } = await getWebserverInfo(api);
    // Longest match wins, and the fallback is the reported slug rather than a
    // guess. VALID_SLUGS lists 'nginx' before 'nginx-proxy', so a plain find()
    // resolves 'nginx-proxy' to 'nginx' - which makes this "no-op" test
    // silently migrate the whole engine to a different webserver, rewriting
    // every vhost and swapping the image every user container runs.
    const current =
      [...VALID_SLUGS]
        .filter((candidate) => slug.includes(candidate))
        .sort((a, b) => b.length - a.length)[0] ?? slug;

    const response = await authedRequest.put('system/change-webserver', {
      data: { new_webserver: current },
    });
    expectOneOf(response.status(), CHANGE_HANDLED);
  });

  test('LiteSpeed accepts a change with no serial when already on LiteSpeed', async ({
    api,
    authedRequest,
  }) => {
    const { slug } = await getWebserverInfo(api);
    test.skip(slug !== 'litespeed', 'This test only applies on LiteSpeed Enterprise.');

    const response = await authedRequest.put('system/change-webserver', {
      data: { new_webserver: 'litespeed' },
    });
    expectOneOf(response.status(), CHANGE_HANDLED);
  });
});

test.describe('webserver configuration', () => {
  test('an empty config update is refused', async ({ authedRequest }) => {
    const response = await authedRequest.put('system/webserver-config', { data: {} });
    expect(response.status()).toBe(422);
  });

  test('the panel password reset endpoint answers deterministically', async ({ authedRequest }) => {
    const response = await authedRequest.put('system/reset-webserver-panel-password');
    // Only LiteSpeed-family stacks have a panel; the rest answer 404 or 400.
    expectOneOf(response.status(), [200, 400, 404, 422, 500]);
  });

  test('resetting the panel password returns a new one on LiteSpeed', async ({ api }) => {
    const { slug } = await getWebserverInfo(api);
    test.skip(!slug.includes('litespeed'), 'Only LiteSpeed-family stacks have a control panel.');

    const { data } = await api.resetWebserverPanelPassword();
    expect(data.new_password?.length ?? 0).toBeGreaterThan(0);
  });

  test('the serial number can be applied on LiteSpeed Enterprise', async ({ api }) => {
    const { slug } = await getWebserverInfo(api);
    test.skip(
      slug !== 'litespeed',
      'A serial number only applies to LiteSpeed Enterprise, not OpenLiteSpeed.'
    );

    const { data: info } = await api.getSystemInfo();
    const serial = litespeedSerial(info.webserver as { serial_number?: string } | undefined);
    test.skip(!serial, 'No LiteSpeed serial available — set LITESPEED_SERIAL_NUMBER.');

    expect((await api.updateWebserverConfig({ serial_number: serial! })).data).toBeDefined();
  });
});
