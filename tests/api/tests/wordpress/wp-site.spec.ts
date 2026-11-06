import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { expectStatus } from '@/helpers/expect-status';
import { uniqueId } from '@/helpers/random';
import { readPage, waitForPage } from '@/helpers/site-page';
import { installWordPressSite } from '@/helpers/wordpress-site';

interface Option {
  option: string;
  value: string;
}

/** A site run through the typed `wp/` endpoints, each change read back where WordPress shows it. */
test.describe('a WordPress site through the wp/ endpoints', () => {
  test('install, options, language, maintenance, config, auto-update and a database round trip', async ({
    api,
    anonymousRequest,
    userFactory,
  }) => {
    test.setTimeout(600_000);
    const user = await userFactory.createUser();
    const name = user.username;
    const home = `https://${user.domain}/`;
    const servesTitle = (title: string) => (page: { status: number; body: string }) =>
      page.status === 200 && page.body.includes(title);

    await test.step('a project without WordPress says so', async () => {
      expect(await api.wp(name, 'GET', 'core')).toEqual({
        installed: false,
        version: null,
        state: 'no_core',
      });
    });

    const site = await test.step('download, configure and install', async () => {
      const installed = await installWordPressSite(api, user);
      const core = await api.wp<{ installed: boolean; version: string; state: string }>(
        name,
        'GET',
        'core'
      );
      expect(core.installed).toBe(true);
      expect(core.state).toBe('installed');
      expect(core.version).toMatch(/^\d+\.\d+/);
      await waitForPage(
        anonymousRequest,
        home,
        servesTitle(installed.title),
        'WordPress never served'
      );
      return installed;
    });

    await test.step('core updates are listed and an update to latest answers', async () => {
      expect(Array.isArray(await api.wp(name, 'GET', 'core/updates'))).toBe(true);
      const updated = await api.wp<{ message: string }>(name, 'POST', 'core/update', {
        version: 'latest',
      });
      expect(updated.message).toMatch(/^Success:/m);
    });

    const renamed = uniqueId('Renamed ');
    await test.step('an option set through the API is the page title', async () => {
      const options = await api.wp<{ option_name: string }[]>(name, 'GET', 'options');
      expect(options.map((option) => option.option_name)).toContain('blogname');
      expect((await api.wp<Option>(name, 'GET', 'options/blogname')).value).toBe(site.title);
      await api.wp(name, 'PUT', 'options/blogname', { value: renamed });
      await waitForPage(anonymousRequest, home, servesTitle(renamed), 'the new name never showed');
    });

    const replaced = uniqueId('Replaced ');
    await test.step('search-replace dry-run changes nothing, a real run does', async () => {
      const dry = await api.wp<{ message: string }>(name, 'POST', 'search-replace', {
        search: renamed,
        replacement: replaced,
        args: ['--dry-run'],
      });
      expect(dry.message).toMatch(/wp_options\toption_value\t1\t/);
      expect((await api.wp<Option>(name, 'GET', 'options/blogname')).value).toBe(renamed);
      await api.wp(name, 'POST', 'search-replace', { search: renamed, replacement: replaced });
      expect((await api.wp<Option>(name, 'GET', 'options/blogname')).value).toBe(replaced);
    });

    await test.step('a language change is the page language', async () => {
      const set = await api.wp<{ language: string }>(name, 'PUT', 'language', {
        language: 'de_DE',
      });
      expect(set.language).toBe('de_DE');
      await waitForPage(
        anonymousRequest,
        home,
        (page) => /<html[^>]* lang="de(-DE)?"/.test(page.body),
        'the page never switched to German'
      );
    });

    await test.step('maintenance mode takes the site off and back on', async () => {
      expect(await api.wp(name, 'GET', 'maintenance')).toEqual({ enabled: false });
      await api.wp(name, 'PUT', 'maintenance', { enabled: true });
      expect(await api.wp(name, 'GET', 'maintenance')).toEqual({ enabled: true });
      const down = await readPage(anonymousRequest, home);
      await expectStatus(down, 503, 'the site in maintenance mode');
      await api.wp(name, 'PUT', 'maintenance', { enabled: false });
      await waitForPage(anonymousRequest, home, servesTitle(replaced), 'the site stayed down');
    });

    await test.step('the cache flush answers what it flushed', async () => {
      const flushed = await api.wp<{ message: string }>(name, 'POST', 'cache/flush');
      expect(flushed.message).toMatch(/flushed/i);
    });

    await test.step('wp-config constants are listed, set, read and removed', async () => {
      const constants = await api.wp<{ name: string }[]>(name, 'GET', 'config');
      expect(constants.map((entry) => entry.name)).toEqual(
        expect.arrayContaining(['DB_NAME', 'DB_HOST', 'DB_USER'])
      );
      const value = uniqueId('v');
      await api.wp(name, 'PUT', 'config/PA_TEST_CONSTANT', { value });
      expect(await api.wp(name, 'GET', 'config/PA_TEST_CONSTANT')).toMatchObject({ value });
      await api.wp(name, 'PUT', 'config/PA_TEST_RAW', { value: '42', raw: true });
      const config = await api.getFileContent(name, `/${user.domain}/public_html/wp-config.php`);
      expect(config, 'a plain value is written as a string').toMatch(
        new RegExp(`define\\(\\s*'PA_TEST_CONSTANT',\\s*'${value}'\\s*\\)`)
      );
      expect(config, 'a raw value is written as PHP').toMatch(
        /define\(\s*'PA_TEST_RAW',\s*42\s*\)/
      );
      expect(
        await api.wp(name, 'GET', 'config/PA_TEST_RAW?format=json'),
        'format=json reads the PHP value'
      ).toMatchObject({ value: 42 });
      await api.wp(name, 'PUT', 'config/WP_DEBUG', { value: 'false', raw: true });
      expect(await api.wp(name, 'GET', 'config/WP_DEBUG?format=json')).toMatchObject({
        value: false,
      });
      expect(
        await api.wp(name, 'GET', 'config/WP_DEBUG'),
        'the plain read prints false as WP-CLI does'
      ).toMatchObject({ value: '' });
      await api.wp(name, 'DELETE', 'config/PA_TEST_RAW');
      await api.wp(name, 'DELETE', 'config/PA_TEST_CONSTANT');
      expectOneOf((await api.wpRaw(name, 'GET', 'config/PA_TEST_CONSTANT')).status, [404, 422]);
    });

    await test.step('auto-updates switch on for every plugin', async () => {
      const before = await api.wp<{ plugins: string }>(name, 'GET', 'auto-update');
      expect(before.plugins).toBe('off');
      await api.wp(name, 'PUT', 'auto-update', { plugins: 'on' });
      const after = await api.wp<{ plugins: string; plugin_items: Record<string, string> }>(
        name,
        'GET',
        'auto-update'
      );
      expect(after.plugins).toBe('on');
      expect(Object.values(after.plugin_items).every((state) => state === 'enabled')).toBe(true);
    });

    await test.step('an exported database brings a changed option back on import', async () => {
      const file = `${user.domain}/pa-test-export-${uniqueId()}.sql`;
      await api.wp(name, 'POST', 'database/export', { file });
      expect((await api.fileExists(name, `/${file}`)).exists, 'the export wrote its file').toBe(
        true
      );
      await api.wp(name, 'PUT', 'options/blogname', { value: 'changed after the export' });
      await api.wp(name, 'POST', 'database/import', { file });
      expect((await api.wp<Option>(name, 'GET', 'options/blogname')).value).toBe(replaced);
      await api.removeFile(name, `/${file}`);
    });
  });
});
