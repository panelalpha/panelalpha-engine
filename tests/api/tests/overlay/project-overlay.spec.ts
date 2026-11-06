import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { uniqueId } from '@/helpers/random';
import { waitForPage, writeMarkerPage } from '@/helpers/site-page';

/**
 * A project overlay is HTML the proxy writes into every HTML page of the project, before
 * `</body>` or `</head>`. These follow it into the served page and back out.
 */
test.describe('project overlay', () => {
  test('an overlay is written into the served page and removed with it', async ({
    api,
    anonymousRequest,
    userFactory,
  }) => {
    test.setTimeout(240_000);
    const user = await userFactory.createSimpleUser();
    const page = await writeMarkerPage(api, user.username, user.domain);
    const banner = `<div id="pa-test-overlay">${uniqueId('overlay-')}</div>`;

    await test.step('a project starts with no overlay', async () => {
      const state = await api.getProjectOverlay(user.username);
      expect(state).toMatchObject({ html: null, position: null, show_global: true });
      await waitForPage(
        anonymousRequest,
        page.url,
        (answer) => answer.status === 200 && answer.body.includes(page.marker),
        `${page.url} never served the page`
      );
    });

    await test.step('setting it before </body> puts it on the page', async () => {
      const state = await api.setProjectOverlay(user.username, { html: banner });
      expect(state).toMatchObject({ html: banner, position: 'body_end', active: true });
      const served = await waitForPage(
        anonymousRequest,
        page.url,
        (answer) => answer.body.includes(banner),
        'the overlay never reached the served page'
      );
      await expectStatus(served, 200);
      expect(served.body).toContain(`${banner}</body>`);
      expect(served.body, 'the page itself is still there').toContain(page.marker);
    });

    await test.step('moving it to </head> moves it on the page', async () => {
      const state = await api.setProjectOverlay(user.username, {
        html: banner,
        position: 'head_end',
      });
      expect(state.position).toBe('head_end');
      await waitForPage(
        anonymousRequest,
        page.url,
        (answer) => answer.body.includes(`${banner}</head>`),
        'the overlay stayed before </body>'
      );
    });

    await test.step('removing it takes it off the page', async () => {
      const state = await api.deleteProjectOverlay(user.username);
      expect(state).toMatchObject({ html: null, active: false });
      await waitForPage(
        anonymousRequest,
        page.url,
        (answer) => answer.status === 200 && !answer.body.includes(banner),
        'the overlay was still served after DELETE'
      );
    });
  });

  test('an overlay that is not usable HTML for the proxy is refused', async ({
    api,
    sharedEmptyDind,
  }) => {
    const project = await sharedEmptyDind.get();
    test.skip(project === null, 'The engine refuses the DinD template.');
    const username = project!.username;

    for (const [label, data] of [
      ['longer than 16384 bytes', { html: 'x'.repeat(16385) }],
      ['holding a NUL byte', { html: 'a\u0000b' }],
      ['at a position the proxy does not know', { html: '<b>x</b>', position: 'footer' }],
      ['with show_global that is not a boolean', { html: '<b>x</b>', show_global: 'maybe' }],
    ] as const) {
      await test.step(label, async () => {
        await expectStatus(await api.setProjectOverlayRaw(username, data), 422, label);
      });
    }
    expect((await api.getProjectOverlay(username)).html, 'nothing refused was stored').toBeNull();
  });

  test('the overlay of a project that does not exist is 404', async ({ authedRequest }) => {
    for (const method of ['get', 'put', 'delete'] as const) {
      await expectStatus(
        await authedRequest[method]('projects/nosuchproject999/overlay', { data: { html: 'x' } }),
        404,
        `${method.toUpperCase()} overlay`
      );
    }
  });
});
