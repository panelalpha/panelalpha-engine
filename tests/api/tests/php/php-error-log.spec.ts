import { expect, test } from '@/fixtures/test-options';
import type { APIRequestContext } from '@/fixtures/test-options';
import type { EngineApi } from '@/clients/engine-api';
import { expectStatus } from '@/helpers/expect-status';
import { getDomainBasePath } from '@/helpers/file-path-helpers';
import { uniqueId } from '@/helpers/random';
import { skipUnless } from '@/helpers/test-helpers';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';
import { phpErrorLogResponseSchema } from '@/schemas';

const LOG_TIMEOUT_MS = 20_000;
const LOG_POLL_INTERVAL_MS = 1_000;
const PROBE_TIMEOUT_MS = 60_000;

/** Writes a PHP probe that raises a warning with `marker` and asks the site for it. */
async function raiseWarning(
  api: EngineApi,
  http: APIRequestContext,
  username: string,
  domain: string,
  body: string
): Promise<void> {
  const script = `php-error-${uniqueId()}.php`;
  await api.putFileContents(username, `${getDomainBasePath(domain)}/${script}`, body);
  await expect
    .poll(
      async () =>
        (
          await http.get(`https://${domain}/${script}?t=${Date.now()}`, {
            ignoreHTTPSErrors: true,
          })
        ).status(),
      {
        timeout: PROBE_TIMEOUT_MS,
        intervals: [LOG_POLL_INTERVAL_MS],
        message: 'the probe script did not answer over HTTP',
      }
    )
    .toBeLessThan(500);
}

test.describe('PHP error log', () => {
  test('a warning from a served script reaches the project log', async ({
    api,
    anonymousRequest,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();
    const marker = `PA_LOG_WARNING_${uniqueId()}`;

    await raiseWarning(
      api,
      anonymousRequest,
      user.username,
      user.domain,
      `<?php trigger_error('${marker}', E_USER_WARNING); echo 'ok';`
    );

    await expect
      .poll(
        async () => {
          const log = validateParsedApiResponse(
            await api.getPhpErrorLog(user.username, { lines: 500 }),
            phpErrorLogResponseSchema
          ).data;
          return log.lines.some((line) => line.includes(marker));
        },
        {
          timeout: LOG_TIMEOUT_MS,
          intervals: [LOG_POLL_INTERVAL_MS],
          message: 'the warning never reached php/error-log',
        }
      )
      .toBe(true);
  });

  test('a fresh project has an empty log file and answers the empty shape', async ({
    api,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();

    const log = validateParsedApiResponse(
      await api.getPhpErrorLog(user.username),
      phpErrorLogResponseSchema
    ).data;

    expect(log.file, 'the log is created empty for the account').toBe('php_error.log');
    expect(log.lines).toEqual([]);
    expect(log.size).toBe(0);
    expect(log.mtime).not.toBeNull();
    expect(log.truncated).toBe(false);
  });

  test('lines windows the tail and since drops older lines', async ({
    api,
    anonymousRequest,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();
    const marker = `PA_LOG_WINDOW_${uniqueId()}`;

    await raiseWarning(
      api,
      anonymousRequest,
      user.username,
      user.domain,
      `<?php trigger_error('${marker}-one', E_USER_WARNING); trigger_error('${marker}-two', E_USER_WARNING); echo 'ok';`
    );

    await expect
      .poll(
        async () => {
          const log = (await api.getPhpErrorLog(user.username, { lines: 500 })).data;
          return log.lines.filter((line) => line.includes(marker)).length;
        },
        {
          timeout: LOG_TIMEOUT_MS,
          intervals: [LOG_POLL_INTERVAL_MS],
          message: 'both warnings never reached the log',
        }
      )
      .toBeGreaterThanOrEqual(2);

    const one = validateParsedApiResponse(
      await api.getPhpErrorLog(user.username, { lines: 1 }),
      phpErrorLogResponseSchema
    ).data;
    expect(one.lines.length).toBe(1);
    expect(one.truncated).toBe(true);

    const future = new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString();
    const none = validateParsedApiResponse(
      await api.getPhpErrorLog(user.username, { since: future }),
      phpErrorLogResponseSchema
    ).data;
    expect(none.lines).toEqual([]);
  });

  test('a user ini_set error_log is not admin-locked and still redirects', async ({
    api,
    anonymousRequest,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();
    const marker = `PA_LOG_CUSTOM_${uniqueId()}`;
    const customLog = `custom-error-${uniqueId()}.log`;

    await raiseWarning(
      api,
      anonymousRequest,
      user.username,
      user.domain,
      `<?php ini_set('error_log', __DIR__ . '/${customLog}'); trigger_error('${marker}', E_USER_WARNING); echo 'ok';`
    );

    await expect
      .poll(
        async () => {
          try {
            return (
              await api.getFileContent(
                user.username,
                `${getDomainBasePath(user.domain)}/${customLog}`
              )
            ).includes(marker);
          } catch {
            return false;
          }
        },
        {
          timeout: LOG_TIMEOUT_MS,
          intervals: [LOG_POLL_INTERVAL_MS],
          message: 'ini_set did not redirect the warning',
        }
      )
      .toBe(true);

    const log = (await api.getPhpErrorLog(user.username, { lines: 500 })).data;
    expect(log.lines.some((line) => line.includes(marker))).toBe(false);
  });

  test('php_error.log is listed for all webservers and downloads', async ({
    api,
    anonymousRequest,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();
    const marker = `PA_LOG_DOWNLOAD_${uniqueId()}`;

    await raiseWarning(
      api,
      anonymousRequest,
      user.username,
      user.domain,
      `<?php trigger_error('${marker}', E_USER_WARNING); echo 'ok';`
    );

    await expect
      .poll(
        async () => {
          const log = (await api.getPhpErrorLog(user.username, { lines: 500 })).data;
          return log.lines.some((line) => line.includes(marker));
        },
        {
          timeout: LOG_TIMEOUT_MS,
          intervals: [LOG_POLL_INTERVAL_MS],
          message: 'the warning never reached the log',
        }
      )
      .toBe(true);

    const listed = (await api.listDomainLogFiles(user.username, user.domain, true)).data;
    const entry = listed.find((file) => file.file === 'php_error.log');
    expect(entry, 'php_error.log is missing from log-files?all_webservers=1').toBeTruthy();
    expect(entry?.size).toBeGreaterThan(0);

    const content = await api.downloadLogFile(user.username, user.domain, 'php_error.log', true);
    expect(content.toString()).toContain(marker);
  });

  test('domain is accepted for the project and refused for another', async ({
    api,
    authedRequest,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();

    const own = validateParsedApiResponse(
      await api.getPhpErrorLog(user.username, { domain: user.domain }),
      phpErrorLogResponseSchema
    ).data;
    expect(own.file === null || typeof own.file === 'string').toBe(true);

    await expectStatus(
      await authedRequest.get(
        `projects/${user.username}/php/error-log?domain=missing-${uniqueId()}.example`
      ),
      404
    );
  });

  test('a dind project answers the container shape with file null', async ({
    api,
    sharedEmptyDind,
  }) => {
    const user = await sharedEmptyDind.get();
    skipUnless(user, 'DinD is not available on this engine.');

    const log = validateParsedApiResponse(
      await api.getPhpErrorLog(user.username, { lines: 10 }),
      phpErrorLogResponseSchema
    ).data;

    expect(log.file).toBeNull();
    expect(log.mtime).toBeNull();
    expect(Array.isArray(log.lines)).toBe(true);
  });
});
