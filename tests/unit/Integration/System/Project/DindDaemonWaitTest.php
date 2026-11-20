<?php

namespace Tests\Integration\System\Project;

use PHPUnit\Framework\TestCase;
use Tests\Support\DindDaemonWaitFixtures;

/**
 * A DinD account has two things to wait for, and only one of them was named.
 *
 * Every staging copy of a DinD project failed with `failed to
 * connect to the docker API at unix:///var/run/docker.sock ... no such file or
 * directory`, the `CreateStaging` job then deleted the destination account,
 * and `GET /projects/{dest}` answered 404. The account container starts in
 * about three seconds; the nested `dockerd` needs a moment longer and the
 * socket does not exist at all until it is ready. `Projects::copy()` reaches
 * its first inner `docker compose` about five seconds in, inside that window.
 *
 * `awaitReady()` was the method for exactly this, and it was inherited from
 * `Runtime` as an empty no-op -- so the deploy path only ever got away with the
 * race by being slow. The test is not about the sleep: it is that the no-op
 * cannot come back unnoticed.
 *
 * The socket appears 5-6s after the container starts. With the wait in place a
 * staging copy of a DinD project completes (`async_status.staging =
 * completed`), the copied stack comes up in the new account, and the staging
 * vhost answers 200 through the proxy.
 */
class DindDaemonWaitTest extends TestCase
{
    use DindDaemonWaitFixtures;

    public function test_it_returns_as_soon_as_the_daemon_answers(): void
    {
        $state = $this->probe([true]);
        $this->dind($state)->awaitReady(tries: 5, intervalSeconds: 0);

        $this->assertSame(1, $state->probes, 'a daemon that is already up must cost one probe, not a sleep');
    }

    public function test_it_waits_for_a_daemon_that_is_not_up_yet(): void
    {
        $state = $this->probe([false, false, true]);
        $this->dind($state)->awaitReady(tries: 5, intervalSeconds: 0);

        $this->assertSame(3, $state->probes, 'the socket is absent for the first seconds after a container starts');
    }

    /**
     * Not `test -S`: the socket file exists before the daemon behind it can serve a request.
     */
    public function test_the_probe_is_a_request_to_the_account_s_daemon(): void
    {
        $state = $this->probe([true]);
        $this->dind($state)->awaitReady(tries: 1, intervalSeconds: 0);

        $this->assertSame(['pa-root account-docker.ping -- test'], $state->commands);
    }
}
