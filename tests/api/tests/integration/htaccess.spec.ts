import { expect, test } from '@/fixtures/test-options';
import { CleanupSteps } from '@/helpers/cleanup-step';
import {
  getHtaccessPollOptions,
  getHtaccessPropagationDelay,
  pollForHtaccessResult,
  skipIfHtaccessUnsupported,
} from '@/helpers/htaccess-helpers';
import { removeFileIfPresent } from '@/helpers/file-path-helpers';
import { delay } from '@/helpers/retry';
import { httpScheme } from '@/helpers/webserver-helpers';
import { expectStatus } from '@/helpers/expect-status';

/**
 * A `.htaccess` dropped into the document root has to take effect without any
 * panel action — that is what site owners and most WordPress plugins rely on.
 *
 * nginx has no `.htaccess` at all, so these skip there.
 */
test.describe('.htaccess', () => {
  let webserver: Awaited<ReturnType<typeof skipIfHtaccessUnsupported>>;
  let htaccessPath: string;
  const createdFiles: string[] = [];

  test.beforeEach(async ({ api, anonymousRequest, settings, setupUser }) => {
    webserver = await skipIfHtaccessUnsupported(test, api, anonymousRequest, settings, setupUser);
    htaccessPath = `/${setupUser.domain}/public_html/.htaccess`;
    createdFiles.length = 0;
  });

  test.afterEach(async ({ api, setupUser }) => {
    // beforeEach can skip before it sets the path, and Playwright still runs this hook.
    if (!htaccessPath) {
      return;
    }

    const cleanup = new CleanupSteps();
    for (const file of createdFiles) {
      await cleanup.run(`remove ${file}`, () => removeFileIfPresent(api, setupUser.username, file));
    }
    await cleanup.run(`remove ${htaccessPath}`, () =>
      removeFileIfPresent(api, setupUser.username, htaccessPath)
    );
    await cleanup.finish();
  });

  /** Writes `.htaccess`, then waits out the reload this webserver needs. */
  const applyHtaccess = async (
    api: Parameters<typeof skipIfHtaccessUnsupported>[1],
    username: string,
    contents: string,
    propagationDelay: number
  ) => {
    await api.putFileContents(username, htaccessPath, contents);
    await delay(getHtaccessPropagationDelay(webserver, propagationDelay));
  };

  test('a rewrite rule takes effect', async ({ api, anonymousRequest, settings, setupUser }) => {
    const contents = `htaccess-test-content-${Date.now()}`;
    const targetFile = 'target.txt';
    const targetPath = `/${setupUser.domain}/public_html/${targetFile}`;
    createdFiles.push(targetPath);

    await test.step('write the target file', async () => {
      await api.putFileContents(setupUser.username, targetPath, contents);
    });
    await test.step('apply the rewrite rule', async () => {
      await applyHtaccess(
        api,
        setupUser.username,
        `<IfModule mod_rewrite.c>\n  RewriteEngine On\n  RewriteRule ^htaccess-test$ ${targetFile} [L]\n</IfModule>\n`,
        settings.timing.propagationDelay
      );
    });

    const url = `${httpScheme(settings.apiBaseUrl)}://${setupUser.domain}/htaccess-test`;
    const { timeoutMs, intervalMs } = getHtaccessPollOptions(webserver);

    await test.step('wait for the rewritten target to be served', async () => {
      const result = await pollForHtaccessResult(anonymousRequest, url, {
        expectStatus: 200,
        expectBodyContent: contents,
        timeoutMs,
        intervalMs,
      });

      expect(result.success, `${url} never served the rewritten target on ${webserver}`).toBe(true);
    });
  });

  test('a rewrite rule can be changed and the change takes effect', async ({
    api,
    anonymousRequest,
    settings,
    setupUser,
  }) => {
    const first = `htaccess-mod-1-${Date.now()}`;
    const second = `htaccess-mod-2-${Date.now()}`;
    const base = `/${setupUser.domain}/public_html`;

    createdFiles.push(`${base}/target1.txt`, `${base}/target2.txt`);
    await test.step('write both target files', async () => {
      await api.putFileContents(setupUser.username, `${base}/target1.txt`, first);
      await api.putFileContents(setupUser.username, `${base}/target2.txt`, second);
    });

    const url = `${httpScheme(settings.apiBaseUrl)}://${setupUser.domain}/htaccess-mod`;
    const { timeoutMs, intervalMs } = getHtaccessPollOptions(webserver);

    const routeTo = async (file: string, expected: string) => {
      await applyHtaccess(
        api,
        setupUser.username,
        `<IfModule mod_rewrite.c>\n  RewriteEngine On\n  RewriteRule ^htaccess-mod$ ${file} [L]\n</IfModule>\n`,
        settings.timing.propagationDelay
      );

      const result = await pollForHtaccessResult(anonymousRequest, url, {
        expectStatus: 200,
        expectBodyContent: expected,
        timeoutMs,
        intervalMs,
      });
      expect(result.success, `${url} did not route to ${file} on ${webserver}`).toBe(true);
    };

    await test.step('route to the first target', () => routeTo('target1.txt', first));
    await test.step('change the rule to the second target', () => routeTo('target2.txt', second));
  });

  test('a custom response header is applied', async ({
    api,
    anonymousRequest,
    settings,
    setupUser,
  }) => {
    const testFile = 'headers-test.txt';
    const contents = `headers-test-content-${Date.now()}`;
    const headerValue = `TestCustomHeader-${Date.now()}`;
    const targetPath = `/${setupUser.domain}/public_html/${testFile}`;
    createdFiles.push(targetPath);

    await test.step('write the target file', async () => {
      await api.putFileContents(setupUser.username, targetPath, contents);
    });
    await test.step('apply the header rule', async () => {
      await applyHtaccess(
        api,
        setupUser.username,
        `<IfModule mod_headers.c>\n  <Files "${testFile}">\n    Header set X-Test-Custom "${headerValue}"\n  </Files>\n</IfModule>\n`,
        settings.timing.propagationDelay
      );
    });

    const url = `${httpScheme(settings.apiBaseUrl)}://${setupUser.domain}/${testFile}`;
    await test.step('check the response header', async () => {
      const response = await anonymousRequest.get(url, { ignoreHTTPSErrors: true });

      await expectStatus(response, 200);
      expect(
        response.headers()['x-test-custom'],
        `${webserver} did not apply the Header directive`
      ).toBe(headerValue);
    });
  });

  test('a 301 redirect is honoured', async ({ api, anonymousRequest, settings, setupUser }) => {
    const targetFile = 'redirect-target.txt';
    const contents = `redirect-test-content-${Date.now()}`;
    const targetPath = `/${setupUser.domain}/public_html/${targetFile}`;
    createdFiles.push(targetPath);

    await test.step('write the target file', async () => {
      await api.putFileContents(setupUser.username, targetPath, contents);
    });
    await test.step('apply the redirect rule', async () => {
      await applyHtaccess(
        api,
        setupUser.username,
        `<IfModule mod_rewrite.c>\n  RewriteEngine On\n  RewriteRule ^old-path$ /${targetFile} [R=301,L]\n</IfModule>\n`,
        settings.timing.propagationDelay
      );
    });

    const url = `${httpScheme(settings.apiBaseUrl)}://${setupUser.domain}/old-path`;

    await test.step('check the 301 response', async () => {
      const redirect = await anonymousRequest.get(url, {
        ignoreHTTPSErrors: true,
        maxRedirects: 0,
      });
      await expectStatus(redirect, 301, `${url} did not redirect on ${webserver}`);
      expect(redirect.headers().location, 'the Location header points at the target').toContain(
        targetFile
      );
    });

    await test.step('follow the redirect', async () => {
      const followed = await anonymousRequest.get(url, {
        ignoreHTTPSErrors: true,
        maxRedirects: 5,
      });
      await expectStatus(followed, 200);
      expect(await followed.text(), 'the redirect target serves the file content').toContain(
        contents
      );
    });
  });
});
