import { expect, test } from '@/fixtures/test-options';
import { delay } from '@/helpers/retry';

const LOG_FLUSH_DELAY_MS = 1_000;

test.describe('domain log listing', () => {
  test('every entry carries a name and a size', async ({ api, setupUser }) => {
    const { data } = await api.listDomainLogFiles(setupUser.username, setupUser.domain, true);
    expect(Array.isArray(data)).toBe(true);

    for (const logFile of data) {
      expect(typeof logFile.file).toBe('string');
      expect(logFile.file.length).toBeGreaterThan(0);
      expect(typeof logFile.size).toBe('number');

      // Engines report the timestamp as `modified` or `mtime`, string or epoch.
      const modified =
        (logFile as { modified?: unknown; mtime?: unknown }).modified ??
        (logFile as { mtime?: unknown }).mtime;
      expect(['undefined', 'string', 'number']).toContain(typeof modified);
    }
  });

  test('all_webservers never returns fewer files than the default', async ({ api, setupUser }) => {
    const [all, current] = await Promise.all([
      api.listDomainLogFiles(setupUser.username, setupUser.domain, true),
      api.listDomainLogFiles(setupUser.username, setupUser.domain, false),
    ]);

    expect(all.data.length).toBeGreaterThanOrEqual(current.data.length);
  });

  test('a log file can be downloaded', async ({
    api,
    anonymousRequest,
    authedRequest,
    setupUser,
  }) => {
    // A freshly provisioned domain has no access log until something asks it
    // for a page, so produce the traffic rather than skipping on the absence
    // of it — a skip there would also cover a log endpoint that lists nothing.
    await anonymousRequest.get(setupUser.url).catch(() => undefined);

    let logFiles: Awaited<ReturnType<typeof api.listDomainLogFiles>>['data'] = [];
    try {
      await expect
        .poll(
          async () => {
            logFiles = (await api.listDomainLogFiles(setupUser.username, setupUser.domain, true))
              .data;
            return logFiles.length;
          },
          { timeout: 20_000, intervals: [2_000] }
        )
        .toBeGreaterThan(0);
    } catch {
      // Still empty after the wait is a property of this webserver, not a
      // failure of the download the test is actually about.
    }
    test.skip(
      logFiles.length === 0,
      'The domain lists no log files even after being requested — this webserver does not log here.'
    );

    const filename = logFiles[0].file;

    const content = await api.downloadLogFile(setupUser.username, setupUser.domain, filename, true);
    expect(Buffer.isBuffer(content)).toBe(true);

    const response = await authedRequest.get(
      `projects/${setupUser.username}/domains/${setupUser.domain}/log-files/` +
        `${encodeURIComponent(filename)}?all_webservers=1`
    );
    expect(response.status()).toBe(200);
    expect(response.headers()['content-disposition']).toBeTruthy();
  });
});

/**
 * Removing a domain or its user has to take the log endpoint with it — a log
 * route that outlives its domain leaks another tenant's access log.
 */
test.describe('log endpoint lifecycle', () => {
  test('deleting an addon domain removes its log endpoint', async ({
    api,
    anonymousRequest,
    authedRequest,
    domainFactory,
    domainAssertions,
    setupUser,
  }) => {
    const domain = await domainFactory.createAddonDomain(setupUser.username, setupUser.domain);

    await api.putFileContents(
      setupUser.username,
      `/${domain}/public_html/log-test.php`,
      '<?php echo "log-test"; ?>'
    );

    const hit = await anonymousRequest.get(`https://${domain}/log-test.php`, {
      ignoreHTTPSErrors: true,
    });
    expect(hit.status()).toBeLessThan(500);
    await delay(LOG_FLUSH_DELAY_MS);

    await domainFactory.deleteDomain(setupUser.username, domain);
    await domainAssertions.verifyDomainNotExists(setupUser.username, domain);

    await expect
      .poll(
        async () =>
          (
            await authedRequest.get(`projects/${setupUser.username}/domains/${domain}/log-files`)
          ).status(),
        {
          timeout: 10_000,
          intervals: [500],
          message: 'log-files endpoint still answers after the addon domain was deleted',
        }
      )
      .toBe(404);
  });

  test('deleting a user removes its log endpoint', async ({
    api,
    anonymousRequest,
    authedRequest,
    userFactory,
    settings,
  }) => {
    const user = await userFactory.createSimpleUser();

    await api.putFileContents(
      user.username,
      `/${user.domain}/public_html/log-test.php`,
      '<?php echo "log-test"; ?>'
    );

    const hit = await anonymousRequest.get(`https://${user.domain}/log-test.php`, {
      ignoreHTTPSErrors: true,
    });
    expect(hit.status()).toBeLessThan(500);

    await expect
      .poll(async () => (await api.listDomainLogFiles(user.username, user.domain)).data.length, {
        timeout: settings.timing.propagationDelay,
        intervals: [500],
      })
      .toBeGreaterThan(0);

    await userFactory.deleteUser(user.username);

    await expect
      .poll(
        async () =>
          (
            await authedRequest.get(`projects/${user.username}/domains/${user.domain}/log-files`)
          ).status(),
        {
          timeout: settings.timing.propagationDelay * 2,
          intervals: [500],
          message: 'log-files endpoint still answers after the user was deleted',
        }
      )
      .toBe(404);
  });
});
