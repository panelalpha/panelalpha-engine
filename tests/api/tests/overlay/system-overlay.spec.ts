import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { uniqueId } from '@/helpers/random';
import { waitForPage, writeMarkerPage } from '@/helpers/site-page';

/** The global overlay changes every site, so this runs serially and always removes it. */
test.describe('global overlay', () => {
  test('the global overlay reaches every project that shows it, beside its own', async ({
    api,
    anonymousRequest,
    userFactory,
  }) => {
    test.setTimeout(360_000);
    const before = await api.getSystemOverlay();
    test.skip(before.overlay !== null, 'A global overlay is already set on this engine.');

    const showing = await userFactory.createSimpleUser();
    const hiding = await userFactory.createSimpleUser();
    const showingPage = await writeMarkerPage(api, showing.username, showing.domain);
    const hidingPage = await writeMarkerPage(api, hiding.username, hiding.domain);
    const global = `<div id="pa-test-global">${uniqueId('global-')}</div>`;
    const own = `<div id="pa-test-own">${uniqueId('own-')}</div>`;

    await api.setProjectOverlay(hiding.username, { show_global: false });
    await api.setProjectOverlay(showing.username, { html: own });

    try {
      await test.step('setting it answers the stored overlay', async () => {
        const set = await api.setSystemOverlay({ html: global });
        expect(set.overlay).toEqual({ html: global, position: 'body_end' });
        expect((await api.getSystemOverlay()).overlay?.html).toBe(global);
        expect((await api.getProjectOverlay(showing.username)).global?.html).toBe(global);
      });

      await test.step('a project that shows it serves both overlays', async () => {
        const served = await waitForPage(
          anonymousRequest,
          showingPage.url,
          (answer) => answer.body.includes(global) && answer.body.includes(own),
          'the global overlay never reached a project that shows it'
        );
        expect(served.body).toContain(showingPage.marker);
      });

      await test.step('a project with show_global off does not', async () => {
        const served = await waitForPage(
          anonymousRequest,
          hidingPage.url,
          (answer) => answer.status === 200 && answer.body.includes(hidingPage.marker),
          `${hidingPage.url} never served the page`
        );
        expect(served.body).not.toContain(global);
        expect((await api.getProjectOverlay(hiding.username)).active).toBe(false);
      });
    } finally {
      await api.deleteSystemOverlay();
    }

    await test.step('removing it takes it off and leaves the project its own', async () => {
      expect((await api.getSystemOverlay()).overlay).toBeNull();
      const served = await waitForPage(
        anonymousRequest,
        showingPage.url,
        (answer) => !answer.body.includes(global) && answer.body.includes(own),
        'the global overlay was still served after DELETE'
      );
      await expectStatus(served, 200);
    });
  });

  test('a global overlay without HTML is refused', async ({ api }) => {
    await expectStatus(await api.setSystemOverlayRaw({}), 422, 'no html');
    await expectStatus(await api.setSystemOverlayRaw({ html: '' }), 422, 'empty html');
    await expectStatus(
      await api.setSystemOverlayRaw({ html: '<b>x</b>', position: 'footer' }),
      422,
      'unknown position'
    );
  });
});
