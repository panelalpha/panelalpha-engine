import { expect, test } from '@/fixtures/test-options';
import { createApiTransport } from '@/clients/api-transport';
import { expectOneOf } from '@/helpers/expect-one-of';
import {
  SSO_EXCHANGE_PATH,
  SSO_QUERY_PARAM,
  exchangeSsoCredential,
  fetchPage,
  isPhpMyAdminLoggedIn,
  isPhpMyAdminLoginForm,
  phpMyAdminIndexUrl,
  ssoCredentialFrom,
} from '@/helpers/mysql-sso-helpers';
import { rand } from '@/helpers/random';
import { expectStatus } from '@/helpers/expect-status';

/**
 * phpMyAdmin single sign-on: the engine mints a one-shot credential, and
 * following its URL should land the user in an authenticated phpMyAdmin without
 * ever typing database credentials.
 *
 * Not every engine build ships phpMyAdmin. Where that is the case the endpoints
 * answer 404 and the affected tests skip rather than fail.
 */
test.describe('phpMyAdmin SSO', () => {
  test('a login URL is minted', { tag: ['@smoke'] }, async ({ api, setupUser }) => {
    expect((await api.createPhpMyAdminSsoToken(setupUser.username)).data.url).toBeTruthy();
  });

  test('the credential is long and URL-safe', async ({ api, setupUser }) => {
    const { url } = (await api.createPhpMyAdminSsoToken(setupUser.username)).data;
    const credential = ssoCredentialFrom(url);

    expect(credential, `no ${SSO_QUERY_PARAM} in ${url}`).toBeTruthy();
    expect(credential!.length).toBeGreaterThan(10);
    expect(credential).toMatch(/^[a-zA-Z0-9\-_+=/]+$/);
  });

  test('two credentials are never the same', async ({ api, setupUser }) => {
    const [first, second] = await Promise.all([
      api.createPhpMyAdminSsoToken(setupUser.username),
      api.createPhpMyAdminSsoToken(setupUser.username),
    ]);

    expect(first.data.url).toBeTruthy();
    expect(second.data.url).not.toBe(first.data.url);
  });

  /** A single-use credential that still works on the second try is a replayable session. */
  test('a credential cannot be exchanged twice', async ({
    api,
    anonymousRequest,
    playwright,
    setupUser,
  }) => {
    const { url } = (await api.createPhpMyAdminSsoToken(setupUser.username)).data;

    const first = await fetchPage(anonymousRequest, url);
    test.skip(
      first.status !== 200 || !first.body.toLowerCase().includes('phpmyadmin'),
      'phpMyAdmin is not served on this engine.'
    );
    const session = await fetchPage(anonymousRequest, phpMyAdminIndexUrl(setupUser.domain));
    expect(isPhpMyAdminLoggedIn(session.body), 'the first use did not log in').toBe(true);

    // A fresh context holds no cookies, so only the credential itself can log it in.
    const replay = await playwright.request.newContext({ ignoreHTTPSErrors: true });
    try {
      const second = await fetchPage(replay, url);
      expect(isPhpMyAdminLoggedIn(second.body), 'a spent login URL logged in a second time').toBe(
        false
      );
      const index = await fetchPage(replay, phpMyAdminIndexUrl(setupUser.domain));
      expect(
        isPhpMyAdminLoggedIn(index.body),
        'a spent login URL established a second session'
      ).toBe(false);
    } finally {
      await replay.dispose();
    }
  });

  /**
   * PmaSso (core/app/Http/Middleware/PmaSso.php:12-30) answers 404 to every source
   * except the phpMyAdmin container, whatever the bearer token says.
   */
  test('the exchange is refused from the test runner', async ({
    api,
    authedRequest,
    setupUser,
  }) => {
    const credential = ssoCredentialFrom(
      (await api.createPhpMyAdminSsoToken(setupUser.username)).data.url
    );
    expect(credential).toBeTruthy();

    const status = await exchangeSsoCredential(createApiTransport(authedRequest), credential!);
    expect(status, 'the exchange must only be reachable from the phpMyAdmin container').toBe(404);
  });

  const invalidCredentials = ['', 'x', 'invalid-token-value', '@@@', 'token with spaces', '%%%'];

  for (const credential of invalidCredentials) {
    test(`exchanging ${JSON.stringify(credential)} is refused`, async ({ authedRequest }) => {
      test.info().annotations.push({
        type: 'limitation',
        description:
          'From the test runner PmaSso answers 404 before token validation (PmaSso.php:12-30); these cases only prove the source restriction',
      });
      const response = await authedRequest.put(SSO_EXCHANGE_PATH, {
        data: { token: credential },
      });
      expectOneOf(response.status(), [400, 401, 404, 422]);
    });
  }

  test('following the login URL establishes a session', async ({
    api,
    anonymousRequest,
    setupUser,
  }) => {
    const { url } = (await api.createPhpMyAdminSsoToken(setupUser.username)).data;

    const login = await fetchPage(anonymousRequest, url);
    test.skip(
      login.status !== 200 || !login.body.toLowerCase().includes('phpmyadmin'),
      'phpMyAdmin is not served on this engine.'
    );

    const index = await fetchPage(anonymousRequest, phpMyAdminIndexUrl(setupUser.domain));
    await expectStatus(index, 200);
    expect(isPhpMyAdminLoggedIn(index.body), 'phpMyAdmin still shows a login form after SSO').toBe(
      true
    );
  });

  test('a tampered login URL does not establish a session', async ({
    api,
    anonymousRequest,
    setupUser,
  }) => {
    const { url } = (await api.createPhpMyAdminSsoToken(setupUser.username)).data;

    const tampered = await fetchPage(anonymousRequest, `${url}-invalid`);

    expect(tampered.status, 'a tampered credential produced a server error').toBeLessThan(500);
    if (tampered.body.toLowerCase().includes('phpmyadmin')) {
      expect(
        isPhpMyAdminLoginForm(tampered.body),
        'a tampered credential logged straight into phpMyAdmin'
      ).toBe(true);
    }
  });

  test('the session lists the account databases', async ({
    api,
    anonymousRequest,
    mysqlFactory,
    setupUser,
  }) => {
    const database = await mysqlFactory.createDatabase(setupUser.username, rand('sso'));

    try {
      const { url } = (await api.createPhpMyAdminSsoToken(setupUser.username)).data;
      const page = await fetchPage(anonymousRequest, url);

      test.skip(
        page.status !== 200 || !page.body.toLowerCase().includes('phpmyadmin'),
        'phpMyAdmin is not served on this engine.'
      );

      expect(page.body).toContain(database);
    } finally {
      await mysqlFactory.deleteDatabase(setupUser.username, database);
    }
  });

  /** A version banner on a public page tells an attacker exactly which CVEs to try. */
  test('the session page does not advertise server versions', async ({
    api,
    anonymousRequest,
    setupUser,
  }) => {
    const { url } = (await api.createPhpMyAdminSsoToken(setupUser.username)).data;
    const login = await fetchPage(anonymousRequest, url);
    test.skip(
      login.status !== 200 || !login.body.toLowerCase().includes('phpmyadmin'),
      'phpMyAdmin is not served on this engine.'
    );

    const index = await fetchPage(anonymousRequest, phpMyAdminIndexUrl(setupUser.domain));
    await expectStatus(index, 200);
    expect(index.body).not.toMatch(/server version/i);
    expect(index.body).not.toMatch(/protocol version/i);
  });
});
