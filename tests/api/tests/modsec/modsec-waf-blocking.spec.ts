import { expect, test } from '@/fixtures/test-options';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { expectNotOneOf } from '@/helpers/expect-one-of';
import {
  AJAX_ACTION,
  AJAX_PLUGIN_CONTENTS,
  AJAX_PLUGIN_FILENAME,
  isAuthorEnumerationMitigated,
  isBlockedBody,
  isBlockedResponse,
  isBlockedStatus,
  modSecMatchesSnapshot,
  modsecUnavailableReason,
  restoreModSec,
  snapshotModSec,
  type ModSecSnapshot,
} from '@/helpers/modsec-helpers';
import { redactAndTruncate } from '@/helpers/redact';
import { waitForCondition } from '@/helpers/retry';
import {
  getWebserverInfo,
  getWebserverPropagationDelay,
  waitForSiteHttpReady,
} from '@/helpers/webserver-helpers';
import { expectStatus } from '@/helpers/expect-status';

/**
 * What the WAF must block, and — just as importantly — what it must not.
 *
 * Every test here runs with ModSecurity on and every ruleset enabled, the
 * strictest configuration a customer can end up in. The mode, the ruleset
 * states and the test plugin are all restored afterwards.
 */
test.describe('ModSecurity WAF', () => {
  let snapshot: ModSecSnapshot;
  let pluginPath: string;
  let ajaxUrl: string;

  test.beforeEach(async ({ api, anonymousRequest, setupUser }) => {
    const reason = await modsecUnavailableReason(api, anonymousRequest, setupUser.url);
    test.skip(Boolean(reason), reason ?? '');
    test.skip(!setupUser.wpPath, 'The setup user has no WordPress installation.');

    snapshot = await snapshotModSec(api);

    const pluginDir = `${setupUser.wpPath}/wp-content/mu-plugins`;
    pluginPath = `${pluginDir}/${AJAX_PLUGIN_FILENAME}`;
    ajaxUrl = `https://${setupUser.domain}/wp-admin/admin-ajax.php`;

    await api.createDirectory(setupUser.username, pluginDir, true);
    await api.putFileContents(setupUser.username, pluginPath, AJAX_PLUGIN_CONTENTS);

    for (const ruleset of snapshot.rulesets.filter((entry) => !entry.enabled)) {
      await api.enableModSecurityRuleset(ruleset.name);
    }

    await api.setModSecurityConfig({ mode: 'on' });
    await waitForSiteHttpReady(anonymousRequest, `https://${setupUser.domain}/`, {
      timeout: 45_000,
      interval: 2_000,
    });

    // The rules are live once a request they forbid is refused; that is the
    // same check the "must be blocked" tests make.
    const blockProbeUrl = `https://${setupUser.domain}/wp-config.php`;
    const slug = (await getWebserverInfo(api)).slug;
    let last = 'no response yet';
    await waitForCondition(
      async () => {
        try {
          const response = await anonymousRequest.get(blockProbeUrl, {
            ignoreHTTPSErrors: true,
            timeout: 15_000,
          });
          last = `HTTP ${response.status()}`;
          return isBlockedStatus(response.status()) || isBlockedBody(await response.text());
        } catch (error) {
          last = `request failed: ${redactAndTruncate(error instanceof Error ? error.message : String(error), 300)}`;
          return false;
        }
      },
      {
        timeout: Math.max(getWebserverPropagationDelay(slug), 30_000),
        interval: 1_000,
        message: `${blockProbeUrl} was never blocked after ModSecurity was switched on`,
        describeLast: () => last,
      }
    );
  });

  test.afterEach(async ({ api, anonymousRequest, setupUser }) => {
    // beforeEach can skip before it sets these, and Playwright still runs this hook.
    if (!snapshot || !pluginPath) {
      return;
    }

    const cleanup = new CleanupSteps();
    await cleanup.run(`remove ${pluginPath}`, () => api.removeFile(setupUser.username, pluginPath));
    await cleanup.run('restore ModSecurity', () => restoreModSec(api, snapshot), {
      verify: () => modSecMatchesSnapshot(api, snapshot),
      describe: () => 'mode or a ruleset is not back to the snapshot',
    });
    // The restore is verified through the API; what is left is the reload
    // reaching the webserver, which shows as the site serving again.
    await cleanup.run('wait for the site to serve again', () =>
      waitForSiteHttpReady(anonymousRequest, `https://${setupUser.domain}/`, {
        timeout: 30_000,
        interval: 1_000,
      })
    );
    await cleanup.finish();
  });

  /**
   * One test, not five: every scenario here needs the same ModSecurity switch
   * plus propagation, which dominates the cost. Each step is a former test.
   */
  test.describe('what must be blocked', () => {
    test(
      'the WAF blocks every probe it must block',
      { tag: ['@security'] },
      async ({ anonymousRequest, setupUser }) => {
        await test.step('/xmlrpc.php is blocked', async () => {
          const url = `https://${setupUser.domain}/xmlrpc.php`;
          const response = await anonymousRequest.get(url, { ignoreHTTPSErrors: true });
          const body = await response.text();

          expect
            .soft(
              isBlockedStatus(response.status()) || isBlockedBody(body),
              `${url} answered ${response.status()} instead of being blocked`
            )
            .toBe(true);
        });

        await test.step('/wp-config.php is blocked', async () => {
          const url = `https://${setupUser.domain}/wp-config.php`;
          const response = await anonymousRequest.get(url, { ignoreHTTPSErrors: true });
          const body = await response.text();

          expect
            .soft(
              isBlockedStatus(response.status()) || isBlockedBody(body),
              `${url} answered ${response.status()} instead of being blocked`
            )
            .toBe(true);

          // The OWASP Core Rule Set ships a restricted-files rule that must
          // keep wp-config.php — which holds the database credentials — off
          // the web.
          await test.step('OWASP CRS blocks HTTP access to wp-config.php', async () => {
            // The rules reach the running webserver a moment after the API
            // accepts them, so this polls rather than asserting on the first
            // response.
            let last = 'no response yet';
            await waitForCondition(
              async () => {
                try {
                  const polled = await anonymousRequest.get(url, {
                    ignoreHTTPSErrors: true,
                    timeout: 15_000,
                  });
                  const polledBody = await polled.text();
                  last = `HTTP ${polled.status()}, body ${redactAndTruncate(polledBody, 300)}`;
                  return isBlockedResponse(polled.status(), polledBody);
                } catch (error) {
                  last = `request failed: ${redactAndTruncate(error instanceof Error ? error.message : String(error), 300)}`;
                  return false;
                }
              },
              {
                timeout: 60_000,
                interval: 2_000,
                message: `${url} was never blocked by OWASP CRS`,
                describeLast: () => last,
              }
            );

            const final = await anonymousRequest.get(url, { ignoreHTTPSErrors: true });
            expect
              .soft(await final.text(), 'the response body leaked database credentials')
              .not.toMatch(/DB_PASSWORD/i);
          });
        });

        await test.step('author enumeration via ?author=1 is mitigated', async () => {
          const url = `https://${setupUser.domain}/?author=1`;
          const response = await anonymousRequest.get(url, { ignoreHTTPSErrors: true });

          expect
            .soft(
              isAuthorEnumerationMitigated({
                status: response.status(),
                body: await response.text(),
                finalUrl: response.url(),
              }),
              `${url} served an author archive, leaking a username`
            )
            .toBe(true);
        });

        await test.step('the REST users endpoint lists nothing anonymously', async () => {
          const url = `https://${setupUser.domain}/wp-json/wp/v2/users?per_page=100`;
          const response = await anonymousRequest.get(url, { ignoreHTTPSErrors: true });
          const body = await response.text();

          expect
            .soft(
              isBlockedStatus(response.status()) ||
                response.status() === 401 ||
                isBlockedBody(body) ||
                /rest_user_cannot_view/i.test(body),
              `${url} answered ${response.status()} and may be listing users`
            )
            .toBe(true);
        });

        await test.step('repeated wp-login.php requests never produce a server error', async () => {
          const url = `https://${setupUser.domain}/wp-login.php`;
          const statuses: number[] = [];

          for (let attempt = 0; attempt < 8; attempt++) {
            const response = await anonymousRequest.get(`${url}?t=${Date.now()}&i=${attempt}`, {
              ignoreHTTPSErrors: true,
            });
            statuses.push(response.status());

            // Being rate-limited is the ideal outcome; stop as soon as it happens.
            if (isBlockedStatus(response.status())) {
              return;
            }
            if (/too many requests|rate limit/i.test(await response.text())) {
              return;
            }
          }

          expect
            .soft(
              statuses.every((status) => [200, 302, 403, 404, 405, 406, 429].includes(status)),
              `wp-login.php answered ${statuses.join(', ')} — one of those is a server error`
            )
            .toBe(true);
        });
      }
    );
  });

  test.describe('what must keep working', () => {
    test('admin-ajax keeps answering through the WAF', async ({ anonymousRequest }) => {
      await test.step('a POST to admin-ajax succeeds', async () => {
        await recordingFailure(async () => {
          const response = await anonymousRequest.post(ajaxUrl, {
            form: { action: AJAX_ACTION, payload: 'ping' },
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            ignoreHTTPSErrors: true,
          });

          expectNotOneOf(response.status(), [403, 406], 'admin-ajax POST was blocked by the WAF');
          await expectStatus(response, 200);
          expect(await response.text()).toContain('"success":true');
        });
      });

      await test.step('a GET to admin-ajax succeeds', async () => {
        await recordingFailure(async () => {
          const response = await anonymousRequest.get(
            `${ajaxUrl}?action=${AJAX_ACTION}&payload=ping`,
            {
              headers: { 'X-Requested-With': 'XMLHttpRequest' },
              ignoreHTTPSErrors: true,
            }
          );

          expectNotOneOf(response.status(), [403, 406], 'admin-ajax GET was blocked by the WAF');
          await expectStatus(response, 200);
          expect(await response.text()).toContain('"success":true');
        });
      });

      /** A burst is what trips an over-eager anomaly score. */
      await test.step('concurrent admin-ajax POSTs all succeed', async () => {
        await recordingFailure(async () => {
          const responses = await Promise.all(
            Array.from({ length: 5 }, () =>
              anonymousRequest.post(ajaxUrl, {
                form: { action: AJAX_ACTION, payload: 'ping' },
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                ignoreHTTPSErrors: true,
              })
            )
          );

          expect(responses.map((response) => response.status())).toEqual([200, 200, 200, 200, 200]);
        });
      });
    });
  });
});

/**
 * Runs one scenario of a merged test. A failure is recorded against the test
 * and the next scenario still runs, so one blocked request does not hide the
 * others.
 */
async function recordingFailure(check: () => Promise<void>): Promise<void> {
  try {
    await check();
  } catch (error) {
    expect.soft(false, error instanceof Error ? error.message : String(error)).toBe(true);
  }
}
