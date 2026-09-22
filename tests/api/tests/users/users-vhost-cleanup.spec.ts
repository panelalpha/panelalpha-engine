import * as path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@/fixtures/test-options';
import { getDomainBasePath, randomFileContent, randomFileName } from '@/helpers/file-path-helpers';
import { fetchSite } from '@/helpers/webserver-helpers';

/**
 * Deleting a user has to take its webserver configuration with it. A leftover
 * vhost that still points at a removed certificate breaks the webserver for
 * every other user on the host, so this is checked on its own.
 *
 * The vhost file itself is not readable from here: it lives in the webserver's
 * config directory, and the only file API the suite has is rooted inside an
 * account's home. So the configuration is observed through the behaviour it
 * produces - a marker file that the account's own vhost serves, and which has
 * to stop being served once the account is gone.
 */
test.describe('vhost cleanup on user deletion', () => {
  test('the account stops being served once it is deleted', async ({
    api,
    anonymousRequest,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();
    const fileName = randomFileName('txt');
    const marker = randomFileContent();
    const url = `https://${user.domain}/${fileName}`;

    await api.putFileContents(
      user.username,
      `${getDomainBasePath(user.domain)}/${fileName}`,
      marker
    );

    // While the account exists its vhost routes the domain at its own docroot.
    // A vhost that has just been written is not serving yet, so a connection
    // error here is a reason to try again rather than to stop — but the last
    // one is carried into the timeout message, because "never served the
    // marker file" and "nothing was ever listening" want different fixes.
    await expect
      .poll(
        async () => {
          try {
            const response = await fetchSite(anonymousRequest, url);
            const body = (await response.text()).trim();
            if (response.status() === 200 && body === marker) {
              return 'served';
            }
            return `HTTP ${response.status()}, body ${JSON.stringify(body.slice(0, 80))}`;
          } catch (error) {
            return error instanceof Error ? error.message : String(error);
          }
        },
        {
          timeout: 60_000,
          intervals: [2_000],
          message: `${url} never served the marker file`,
        }
      )
      .toBe('served');

    await userFactory.deleteUser(user.username);

    // Without a vhost nothing may serve that account's document root. The
    // request itself can still land somewhere - an unmatched Host falls
    // through to whichever vhost nginx loaded first - so the marker, not the
    // status code, is what proves the configuration is gone.
    const afterDelete = await fetchSite(anonymousRequest, url);
    expect(
      (await afterDelete.text()).trim(),
      `${url} still serves the deleted account's document root`
    ).not.toBe(marker);
  });

  test('deleting a user leaves the webserver serving everyone else', async ({
    anonymousRequest,
    userFactory,
    setupUser,
  }) => {
    const doomed = await userFactory.createSimpleUser();
    await userFactory.deleteUser(doomed.username);

    // The failure this guards against is a dangling vhost that references a
    // certificate the deletion removed: nginx then refuses to reload and every
    // other account goes down with it.
    const response = await fetchSite(anonymousRequest, `https://${setupUser.domain}/`, {
      maxRedirects: 5,
    });
    expect(
      response.status(),
      `${setupUser.domain} stopped serving after an unrelated user was deleted`
    ).toBeLessThan(500);
  });
});

/**
 * The engine root, when the suite runs on the engine host.
 *
 * `tests/api` lives inside the engine checkout, so two levels up is the
 * directory holding `docker-compose.yml` and `users/`. Only meaningful for a
 * local runner — over SSH these paths belong to a different machine.
 */
const engineRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');

test.describe('home directory cleanup on user deletion', () => {
  test('deleting a project removes its home directory from the host', async ({
    api,
    hostExec,
    userFactory,
  }) => {
    test.skip(!hostExec, 'pae-artisan is not reachable from this runner.');
    test.skip(
      hostExec!.mode !== 'local',
      'Host paths only mean something when the suite runs on the engine itself.'
    );

    // Deleted on the last line rather than by the factory: the assertion is
    // about what the delete leaves behind, so it has to own the delete.
    const user = await userFactory.createSimpleUser({ autoCleanup: false });

    // `home_dir` is the path inside the container (`/home/<name>`); on the host
    // the same directory is `users/<name>` under the engine root.
    const details = (await api.getUser(user.username)).data;
    const containerHome = details.details?.home_dir ?? details.config?.home_dir;
    expect(containerHome, 'the engine reports no home_dir for the project').toBeTruthy();
    const hostHome = path.join(engineRoot, 'users', path.basename(String(containerHome)));

    const exists = async () => (await hostExec!.run('test', ['-d', hostHome])).exitCode === 0;
    expect(await exists(), `${hostHome} was never created`).toBe(true);

    await api.deleteUser(user.username);

    // `project:delete` documents itself as removing "database rows, server
    // config files and home directory". A directory that outlives its account
    // keeps that customer's files — and their disk usage — on the host, with
    // nothing left in the API to show it is there.
    expect(await exists(), `${hostHome} outlived the project it belonged to`).toBe(false);
  });
});
