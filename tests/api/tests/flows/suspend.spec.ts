import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { readPage, servesMarker, waitForPage, writeMarkerPage } from '@/helpers/site-page';

/**
 * Suspending a project takes its site off the web, and unsuspending puts the same site
 * back. The status field alone says nothing about either, so this reads the site.
 */
test.describe('suspend and unsuspend', () => {
  test('a suspended site stops serving the project and comes back on unsuspend', async ({
    api,
    anonymousRequest,
    userFactory,
  }) => {
    test.setTimeout(240_000);
    const user = await userFactory.createSimpleUser();
    const page = await writeMarkerPage(api, user.username, user.domain);
    await waitForPage(anonymousRequest, page.url, servesMarker(page), 'the site never served');

    await test.step('suspend hides the site', async () => {
      await api.suspendUser(user.username);
      expect((await api.getUser(user.username)).data.status).toBe('suspended');
      await waitForPage(
        anonymousRequest,
        page.url,
        (answer) => !answer.body.includes(page.marker),
        'the suspended project kept serving its page'
      );
    });

    await test.step('the files are still there while suspended', async () => {
      const files = await api.fileExists(user.username, `/${user.domain}/public_html/index.html`);
      expect(files.exists).toBe(true);
    });

    await test.step('unsuspend serves the same page again', async () => {
      await api.unsuspendUser(user.username);
      expect((await api.getUser(user.username)).data.status).toBe('active');
      const back = await waitForPage(
        anonymousRequest,
        page.url,
        servesMarker(page),
        'the unsuspended project did not serve its page again'
      );
      await expectStatus(back, 200, 'the page after unsuspend');
      expect((await readPage(anonymousRequest, page.url)).body).toContain(page.marker);
    });
  });
});
