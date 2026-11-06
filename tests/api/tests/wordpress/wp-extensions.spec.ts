import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { waitForPage } from '@/helpers/site-page';
import { installWordPressSite } from '@/helpers/wordpress-site';

const PLUGIN = 'hello-dolly';
const THEME = 'twentytwentyone';

interface Extension {
  name: string;
  status: string;
}

/**
 * Plugins and themes through the typed endpoints, from the wordpress.org directory to
 * the site: what WordPress lists, and for a theme the stylesheet the served page loads.
 */
test.describe('WordPress plugins and themes', () => {
  test('a plugin is found, installed, switched on and off, updated and deleted', async ({
    api,
    userFactory,
  }) => {
    test.setTimeout(300_000);
    const user = await userFactory.createUser();
    const name = user.username;
    await installWordPressSite(api, user);
    const statusOf = async (slug: string) =>
      (await api.wp<Extension[]>(name, 'GET', 'plugins')).find((entry) => entry.name === slug)
        ?.status;

    await test.step('the directory search finds it', async () => {
      const found = await api.wp<{ slug: string }[]>(
        name,
        'GET',
        `plugins/search?term=${PLUGIN}&per_page=5`
      );
      expect(found.map((entry) => entry.slug)).toContain(PLUGIN);
    });

    await test.step('install', async () => {
      expect(await api.wp(name, 'GET', `plugins/${PLUGIN}/installed`)).toEqual({
        plugin: PLUGIN,
        installed: false,
      });
      const installed = await api.wp<{ message: string }>(name, 'POST', 'plugins/install', {
        slug: PLUGIN,
      });
      expect(installed.message).toMatch(/Installed 1 of 1 plugins/);
      expect(await api.wp(name, 'GET', `plugins/${PLUGIN}/installed`)).toEqual({
        plugin: PLUGIN,
        installed: true,
      });
      expect(await api.wp(name, 'GET', `plugins/${PLUGIN}`)).toMatchObject({
        name: PLUGIN,
        title: 'Hello Dolly',
      });
      expect(await statusOf(PLUGIN)).toBe('inactive');
    });

    await test.step('activate and deactivate', async () => {
      await api.wp(name, 'POST', `plugins/${PLUGIN}/activate`);
      expect(await statusOf(PLUGIN)).toBe('active');
      expect(
        (await api.wp<Extension[]>(name, 'GET', 'plugins?status=active')).map((entry) => entry.name)
      ).toEqual([PLUGIN]);
      await api.wp(name, 'POST', `plugins/${PLUGIN}/deactivate`);
      expect(await statusOf(PLUGIN)).toBe('inactive');
    });

    await test.step('update answers that it is current', async () => {
      const updated = await api.wp<{ message: string }>(name, 'POST', `plugins/${PLUGIN}/update`);
      expect(updated.message).toMatch(/already updated|Updated 1 of 1/);
    });

    await test.step('delete removes it', async () => {
      await api.wp(name, 'DELETE', `plugins/${PLUGIN}`);
      expect(await statusOf(PLUGIN)).toBeUndefined();
      expect(await api.wp(name, 'GET', `plugins/${PLUGIN}/installed`)).toMatchObject({
        installed: false,
      });
    });

    await test.step('a plugin that is not in the directory is refused', async () => {
      expectOneOf(
        (await api.wpRaw(name, 'POST', 'plugins/install', { slug: 'pa-no-such-plugin-0000' }))
          .status,
        [404, 422]
      );
    });
  });

  test('a theme is installed, becomes the stylesheet of the site, and is deleted', async ({
    api,
    anonymousRequest,
    userFactory,
  }) => {
    test.setTimeout(300_000);
    const user = await userFactory.createUser();
    const name = user.username;
    await installWordPressSite(api, user);
    const home = `https://${user.domain}/`;
    const themes = async () => api.wp<Extension[]>(name, 'GET', 'themes');
    const active = async () => (await themes()).find((entry) => entry.status === 'active')?.name;
    const shipped = (await active())!;

    await test.step('search and install', async () => {
      const found = await api.wp<{ slug: string }[]>(name, 'GET', `themes/search?term=${THEME}`);
      expect(found.map((entry) => entry.slug)).toContain(THEME);
      await api.wp(name, 'POST', 'themes/install', { slug: THEME });
      expect(await api.wp(name, 'GET', `themes/${THEME}/installed`)).toEqual({
        theme: THEME,
        installed: true,
      });
      expect(await api.wp(name, 'GET', `themes/${THEME}`)).toMatchObject({
        title: 'Twenty Twenty-One',
        status: 'inactive',
      });
    });

    await test.step('activate it and the page loads its stylesheet', async () => {
      await api.wp(name, 'POST', `themes/${THEME}/activate`);
      expect(await active()).toBe(THEME);
      await waitForPage(
        anonymousRequest,
        home,
        (page) => page.body.includes(`/wp-content/themes/${THEME}/`),
        'the page never used the new theme'
      );
    });

    await test.step('update answers that it is current', async () => {
      const updated = await api.wp<{ message: string }>(name, 'POST', `themes/${THEME}/update`);
      expect(updated.message).toMatch(/already updated|Updated 1 of 1/);
    });

    await test.step('the active theme cannot be deleted', async () => {
      expectOneOf((await api.wpRaw(name, 'DELETE', `themes/${THEME}`)).status, [409, 422]);
      expect(await active()).toBe(THEME);
    });

    await test.step('switch back, delete it, and the page leaves it', async () => {
      await api.wp(name, 'POST', `themes/${shipped}/activate`);
      await api.wp(name, 'DELETE', `themes/${THEME}`);
      expect((await themes()).map((entry) => entry.name)).not.toContain(THEME);
      await waitForPage(
        anonymousRequest,
        home,
        (page) => page.status === 200 && !page.body.includes(`/wp-content/themes/${THEME}/`),
        'the page kept the deleted theme'
      );
    });
  });
});
