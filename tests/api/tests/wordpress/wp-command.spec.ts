import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { expectStatus } from '@/helpers/expect-status';

/** `wp/command` runs a WP-CLI command no typed endpoint covers, refusing option-like arguments. */
test.describe('WordPress commands and refusals', () => {
  test('wp/command runs WP-CLI on the site and answers its output', async ({
    api,
    authedRequest,
    setupUser,
  }) => {
    test.skip(!setupUser.wpPath, 'The setup user has no WordPress installation.');
    const response = await authedRequest.post(`projects/${setupUser.username}/wp/command`, {
      data: { args: ['option', 'get', 'siteurl', `--path=${setupUser.wpPath}`] },
    });
    await expectStatus(response, 200, 'POST wp/command');
    const result = (await response.json()) as { exit_code: number; stdout: string };
    expect(result.exit_code).toBe(0);
    expect(result.stdout.trim()).toBe(`https://${setupUser.domain}`);
    expect(
      (await api.wp<{ value: string }>(setupUser.username, 'GET', 'options/siteurl')).value,
      'the typed endpoint reads the same option'
    ).toBe(result.stdout.trim());
  });

  test('a project without WordPress is refused by every operation that needs it', async ({
    api,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();
    for (const [method, operation] of [
      ['GET', 'plugins'],
      ['GET', 'themes'],
      ['GET', 'users'],
      ['GET', 'options/blogname'],
      ['POST', 'cache/flush'],
    ] as const) {
      const answer = await api.wpRaw(user.username, method, operation);
      expectOneOf(answer.status, [404, 409, 422]);
      expect(JSON.stringify(answer.body), `${method} ${operation}`).toMatch(
        /wordpress|wp-config|install/i
      );
    }
  });

  test('the maintenance state of a project without WordPress is refused too', async ({
    api,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();
    expectOneOf((await api.wpRaw(user.username, 'GET', 'maintenance')).status, [404, 409, 422]);
  });

  test('an argument that WP-CLI would read as an option is refused before it runs', async ({
    api,
    setupUser,
  }) => {
    for (const [method, operation, data] of [
      ['POST', 'plugins/install', { slug: '--activate-network' }],
      ['POST', 'themes/install', { slug: '../../etc' }],
      [
        'POST',
        'users',
        { login: '--role=administrator', email: 'x@example.com', password: 'Pw-12345678' },
      ],
      ['POST', 'search-replace', { search: '--all-tables', replacement: 'x' }],
      ['POST', 'database/export', { file: '--add-drop-table' }],
      ['PUT', 'language', { language: 'de_DE --activate' }],
      ['PUT', 'auto-update', { plugins: 'maybe' }],
      ['PUT', 'maintenance', { enabled: 'sometimes' }],
    ] as const) {
      const answer = await api.wpRaw(setupUser.username, method, operation, data);
      await expectStatus(answer, 422, `${method} ${operation} ${JSON.stringify(data)}`);
    }
    for (const operation of ['plugins/--force', 'themes/a%20b', 'users/--yes', 'options/a%20b']) {
      expectOneOf((await api.wpRaw(setupUser.username, 'GET', operation)).status, [404, 422]);
    }
  });

  test('a project that does not exist is 404', async ({ api }) => {
    for (const operation of ['core', 'plugins', 'users', 'config']) {
      await expectStatus(await api.wpRaw('nosuchproject999', 'GET', operation), 404, operation);
    }
  });
});
