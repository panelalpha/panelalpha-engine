import { expect, test } from '@/fixtures/test-options';
import type { HostExec } from '@/helpers/host-exec';
import { expectStatus } from '@/helpers/expect-status';
import { requireFirewall } from '@/helpers/firewall-helpers';

/** The lock every ufw write on the engine host takes. */
const UFW_LOCK = '/opt/panelalpha/shared-hosting/data/ufw.lock';

/**
 * Longer than the engine's 30 s lock wait, so the reload gives up and answers
 * before the hold ends. Nothing is changed either way.
 */
const LOCK_HOLD_SECONDS = 35;

/** Takes the firewall lock in the background and waits until it is held. */
async function holdFirewallLock(hostExec: HostExec): Promise<void> {
  await hostExec.run('sh', [
    '-c',
    `touch ${UFW_LOCK}; flock -x ${UFW_LOCK} -c 'sleep ${LOCK_HOLD_SECONDS}' >/dev/null 2>&1 &`,
  ]);
  await expect
    .poll(async () => (await hostExec.run('sh', ['-c', `flock -n ${UFW_LOCK} -c true`])).exitCode, {
      timeout: 5_000,
      message: 'this test did not take the firewall lock',
    })
    .not.toBe(0);
}

test.describe('Firewall lock contention', () => {
  test('a reload whose lock is held answers 409, not 502', async ({ api, hostExec }) => {
    await requireFirewall(api);
    test.skip(hostExec === null, 'the host is not reachable to hold the firewall lock.');
    if (hostExec === null) {
      return;
    }

    const flock = await hostExec.run('sh', ['-c', 'command -v flock']);
    test.skip(flock.exitCode !== 0, 'flock is not installed on the host.');

    await holdFirewallLock(hostExec);

    const response = await api.reloadFirewallRaw();
    await expectStatus(response, 409, 'a lock timeout is a retryable busy answer');
  });
});
