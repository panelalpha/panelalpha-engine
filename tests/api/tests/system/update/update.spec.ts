import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { isEngineUnreachable, waitForCondition } from '@/helpers/retry';
import { waitForSystemInfo } from '@/helpers/webserver-helpers';
import type { EngineApi } from '@/clients/engine-api';
import type { SystemChangeStatus } from '@/types';

/**
 * Updating the engine replaces the software the rest of the suite is testing, so
 * this lives in its own Playwright project and never runs as part of `npm test`:
 *
 *     npm run test:update
 */

const UPDATE_POLL_TIMEOUT_MS = 300_000;
const UPDATE_POLL_INTERVAL_MS = 5_000;

function licenseKey(): string | undefined {
  return process.env.SYSTEM_UPDATE_LICENSE_KEY ?? process.env.LICENSE_KEY;
}

/**
 * The update restarts core and sites-http, so a poll can land on a closed
 * socket. `undefined` means "not reachable right now", not "no update".
 */
async function readLatestUpdate(api: EngineApi): Promise<SystemChangeStatus | null | undefined> {
  try {
    return (await api.getSystemInfo()).data.latest_update ?? null;
  } catch (error) {
    if (isEngineUnreachable(error)) {
      return undefined;
    }
    throw error;
  }
}

test.describe('engine update', () => {
  test('an update can be triggered', async ({ api }) => {
    const key = licenseKey();
    const result = await api.updateSystemRaw(key ? { license_key: key } : {});

    test.skip(result.status === 404, 'This engine has no system update route.');
    // 422 is the engine saying an update is already running, which is a valid
    // answer to "start an update" rather than a failure.
    expectOneOf(result.status, [200, 422]);

    if (result.status === 200) {
      expect((result.body as { data?: unknown }).data).toBeDefined();
    } else {
      expect(JSON.stringify(result.body).toLowerCase()).toContain('running');
    }
  });

  test('a non-string license key is refused', async ({ api }) => {
    const { status } = await api.updateSystemRaw({ license_key: 12345 });
    expectOneOf(status, [400, 422]);
  });

  /**
   * An update that never records `finished_at` leaves the engine stuck showing
   * "Building" forever, which is what this watches for.
   */
  test('an update reaches a terminal status', async ({ api }) => {
    const before = (await api.getSystemInfo()).data.latest_update ?? null;

    const key = licenseKey();
    const triggered = await api.updateSystemRaw(key ? { license_key: key } : {});
    test.skip(triggered.status === 404, 'This engine has no system update route.');

    let lastSeen = 'nothing read yet';
    await waitForCondition(
      async () => {
        const latest = await readLatestUpdate(api);
        if (latest === undefined) {
          lastSeen = 'engine unreachable (restarting)';
          return false;
        }
        lastSeen = JSON.stringify(latest);
        const isNewRun =
          latest?.started_at && before?.started_at ? latest.started_at > before.started_at : true;
        return Boolean(isNewRun && latest?.finished_at);
      },
      {
        timeout: UPDATE_POLL_TIMEOUT_MS,
        interval: UPDATE_POLL_INTERVAL_MS,
        message: 'the engine update never recorded a finish time',
        describeLast: () => lastSeen,
      }
    );

    expect((await waitForSystemInfo(api)).data.latest_update?.finished_at).toBeTruthy();
  });
});
