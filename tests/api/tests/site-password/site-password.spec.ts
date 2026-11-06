import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { readPage, servesMarker, waitForPage, writeMarkerPage } from '@/helpers/site-page';
import { uniqueId } from '@/helpers/random';

const COOKIE = 'pa_site_auth';

function sessionCookie(headers: Record<string, string>): string | undefined {
  const match = new RegExp(`${COOKIE}=([^;]+)`).exec(headers['set-cookie'] ?? '');
  return match ? `${COOKIE}=${match[1]}` : undefined;
}

function passwordProtection(body: unknown): unknown {
  return (body as { data?: { details?: { password_protection?: unknown } } }).data?.details
    ?.password_protection;
}

/** The site password gate from outside: hidden site, login, and a new password ending sessions. */
test.describe('site password gate', () => {
  test('the site is hidden behind the form until the visitor logs in', async ({
    api,
    playwright,
    userFactory,
  }) => {
    test.setTimeout(240_000);
    const user = await userFactory.createSimpleUser();
    const page = await writeMarkerPage(api, user.username, user.domain);
    const visitor = await playwright.request.newContext({ ignoreHTTPSErrors: true });
    const password = uniqueId('pw-');

    try {
      await test.step('the site serves the page while no password is set', async () => {
        await waitForPage(
          visitor,
          page.url,
          servesMarker(page),
          `${page.url} never served the page`
        );
      });

      await test.step('setting a password marks the project protected', async () => {
        const set = await api.setSitePasswordRaw(user.username, password);
        await expectStatus(set, 200, 'PUT password');
        expect(passwordProtection(set.body)).toBe(true);
        expect(JSON.stringify(set.body), 'the hash never leaves the engine').not.toContain(
          'site_password_hash'
        );
      });

      const gate = await test.step('a visitor without the password gets the form', () =>
        waitForPage(
          visitor,
          page.url,
          (answer) => answer.status === 401,
          `${page.url} kept serving the site after the password was set`
        ));
      expect(gate.body).not.toContain(page.marker);
      expect(gate.body).toMatch(/<form[^>]*method="post"/i);
      expect(gate.headers['content-type']).toContain('text/html');

      await test.step('a wrong password is refused and gives no session', async () => {
        const wrong = await visitor.post(`https://${user.domain}/_pa_site_password`, {
          form: { password: `${password}-wrong`, redirect: '/' },
          maxRedirects: 0,
        });
        await expectStatus(wrong, 401);
        expect(sessionCookie(wrong.headers())).toBeUndefined();
        expect(await wrong.text()).not.toContain(page.marker);
      });

      const cookie =
        await test.step('the right password redirects back with a session', async () => {
          const login = await visitor.post(`https://${user.domain}/_pa_site_password`, {
            form: { password, redirect: '/' },
            maxRedirects: 0,
          });
          await expectStatus(login, 302);
          const back = new URL(login.headers().location ?? '', page.url);
          expect(`${back.host}${back.pathname}`, 'the login sends the visitor back to /').toBe(
            `${user.domain}/`
          );
          const session = sessionCookie(login.headers());
          expect(session, 'the login sets the gate cookie').toBeDefined();
          return session!;
        });

      await test.step('the session cookie opens the site', async () => {
        const opened = await readPage(visitor, page.url, { headers: { Cookie: cookie } });
        await expectStatus(opened, 200);
        expect(opened.body).toContain(page.marker);
      });

      await test.step('the password also works as HTTP Basic', async () => {
        const basic = Buffer.from(`visitor:${password}`).toString('base64');
        const opened = await readPage(visitor, page.url, {
          headers: { Authorization: `Basic ${basic}` },
        });
        await expectStatus(opened, 200);
        expect(opened.body).toContain(page.marker);
      });

      await test.step('a new password ends the old session', async () => {
        await expectStatus(
          await api.setSitePasswordRaw(user.username, `${password}-next`),
          200,
          'PUT password again'
        );
        await waitForPage(
          visitor,
          page.url,
          (answer) => answer.status === 401,
          'the session from the old password still opens the site',
          { headers: { Cookie: cookie } }
        );
      });

      await test.step('removing the password opens the site to everyone', async () => {
        const removed = await api.deleteSitePasswordRaw(user.username);
        await expectStatus(removed, 200, 'DELETE password');
        expect(passwordProtection(removed.body)).toBe(false);
        await waitForPage(
          visitor,
          page.url,
          servesMarker(page),
          `${page.url} kept the gate after the password was removed`
        );
      });
    } finally {
      await visitor.dispose();
    }
  });

  test('removing a password from a project without one answers the project unchanged', async ({
    api,
    sharedEmptyDind,
  }) => {
    const project = await sharedEmptyDind.get();
    test.skip(project === null, 'The engine refuses the DinD template.');
    const removed = await api.deleteSitePasswordRaw(project!.username);
    await expectStatus(removed, 200, 'DELETE password');
    expect(passwordProtection(removed.body)).toBe(false);
  });

  test('a password for a project that does not exist is 404', async ({ api }) => {
    await expectStatus(await api.deleteSitePasswordRaw('nosuchproject999'), 404);
    await expectStatus(await api.setSitePasswordRaw('nosuchproject999', 'x'), 404);
  });

  test('an empty password is refused', async ({ api, sharedEmptyDind }) => {
    const project = await sharedEmptyDind.get();
    test.skip(project === null, 'The engine refuses the DinD template.');
    await expectStatus(await api.setSitePasswordRaw(project!.username, ''), 422);
  });
});
