import { expect, test } from '@/fixtures/test-options';
import type { APIRequestContext } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { uniqueId } from '@/helpers/random';
import { registerSecret } from '@/helpers/secret-registry';
import { installWordPressSite } from '@/helpers/wordpress-site';

interface WpUser {
  user_login: string;
  display_name: string;
  roles: string;
}

/** True when wp-login.php accepts the login, read from the auth cookie it sets. */
async function logsIn(
  request: APIRequestContext,
  site: string,
  login: string,
  password: string
): Promise<boolean> {
  const response = await request.post(`${site}/wp-login.php`, {
    form: { log: login, pwd: password, 'wp-submit': 'Log In', testcookie: '1' },
    headers: { Cookie: 'wordpress_test_cookie=WP%20Cookie%20check' },
    maxRedirects: 0,
    ignoreHTTPSErrors: true,
  });
  return /wordpress_logged_in_[0-9a-f]+=/.test(response.headers()['set-cookie'] ?? '');
}

/** Each account change is checked by WordPress's own login; REST calls carry no login cookie. */
test.describe('WordPress users', () => {
  test('an account is created, changed, given an app password and SSO, and removed', async ({
    api,
    playwright,
    userFactory,
  }) => {
    test.setTimeout(300_000);
    const user = await userFactory.createUser();
    const name = user.username;
    const site = await installWordPressSite(api, user);
    const login = 'pa-editor';
    const password = uniqueId('Pw-');
    const nextPassword = uniqueId('Pw-');
    registerSecret(password);
    registerSecret(nextPassword);
    const visitor = await playwright.request.newContext({ ignoreHTTPSErrors: true });
    const restClient = await playwright.request.newContext({ ignoreHTTPSErrors: true });
    const users = async () => api.wp<WpUser[]>(name, 'GET', 'users');

    try {
      await test.step('the installer admin is the only account', async () => {
        expect((await users()).map((entry) => entry.user_login)).toEqual([site.adminUser]);
      });

      await test.step('create an editor who can log in', async () => {
        await api.wp(name, 'POST', 'users', {
          login,
          email: `${login}@example.com`,
          password,
          role: 'editor',
        });
        expect((await users()).find((entry) => entry.user_login === login)?.roles).toBe('editor');
        expect(
          (await api.wp<WpUser[]>(name, 'GET', 'users?role=editor')).map((e) => e.user_login)
        ).toEqual([login]);
        expect(await logsIn(visitor, site.url, login, password)).toBe(true);
      });

      await test.step('update the profile and role', async () => {
        await api.wp(name, 'PUT', `users/${login}`, { display_name: 'PA Editor', role: 'author' });
        expect((await users()).find((entry) => entry.user_login === login)).toMatchObject({
          display_name: 'PA Editor',
          roles: 'author',
        });
      });

      await test.step('a new password replaces the old one on wp-login.php', async () => {
        await api.wp(name, 'PUT', `users/${login}/password`, { password: nextPassword });
        expect(await logsIn(visitor, site.url, login, password), 'the old password').toBe(false);
        expect(await logsIn(visitor, site.url, login, nextPassword), 'the new password').toBe(true);
      });

      await test.step('an application password opens the REST API until it is revoked', async () => {
        expect(await api.wp(name, 'GET', `users/${login}/application-passwords`)).toEqual([]);
        const created = await api.wp<{ password: string }>(
          name,
          'POST',
          `users/${login}/application-passwords`,
          { name: 'api suite' }
        );
        registerSecret(created.password);
        const basic = `Basic ${Buffer.from(`${login}:${created.password}`).toString('base64')}`;
        const me = () =>
          restClient.get(`${site.url}/?rest_route=/wp/v2/users/me&context=edit`, {
            headers: { Authorization: basic },
          });
        const answered = await me();
        await expectStatus(answered, 200, 'the REST API with the application password');
        expect(((await answered.json()) as { username: string }).username).toBe(login);

        const listed = await api.wp<{ uuid: string; name: string }[]>(
          name,
          'GET',
          `users/${login}/application-passwords`
        );
        expect(listed.map((entry) => entry.name)).toEqual(['api suite']);
        await api.wp(name, 'DELETE', `users/${login}/application-passwords/${listed[0].uuid}`);
        await expectStatus(await me(), 401, 'the REST API after the revoke');
      });

      await test.step('the SSO link lands logged in on wp-admin', async () => {
        const { url } = await api.wp<{ url: string }>(name, 'POST', `users/${site.adminUser}/sso`);
        expect(url.startsWith(`${site.url}/`)).toBe(true);
        const browser = await playwright.request.newContext({ ignoreHTTPSErrors: true });
        try {
          const landed = await browser.get(url);
          await expectStatus(landed, 200, 'following the SSO link');
          expect(landed.url()).toContain('/wp-admin/');
          expect(await landed.text()).toMatch(/id="wpadminbar"|class="[^"]*wp-admin/);
          const fresh = await playwright.request.newContext({ ignoreHTTPSErrors: true });
          const replay = await fresh.get(url, { maxRedirects: 0 });
          const replayCookies = replay.headers()['set-cookie'] ?? '';
          await fresh.dispose();
          expect(
            replayCookies.includes('wordpress_logged_in_'),
            'a used SSO link logs in a second time'
          ).toBe(false);
        } finally {
          await browser.dispose();
        }
      });

      await test.step('remove the account, its posts going to the admin', async () => {
        await api.wp(name, 'DELETE', `users/${login}?reassign=${site.adminUser}`);
        expect((await users()).map((entry) => entry.user_login)).toEqual([site.adminUser]);
        expect(await logsIn(visitor, site.url, login, nextPassword)).toBe(false);
      });
    } finally {
      await visitor.dispose();
      await restClient.dispose();
    }
  });
});
