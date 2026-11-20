<?php

namespace Tests\Unit\System\Project;

use App\System\Project\Dind;
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

    /** Bounded: the caller's own error is a better message than a hang. */
    public function test_it_gives_up_after_its_tries(): void
    {
        $state = $this->probe([false]);
        $this->dind($state)->awaitReady(tries: 3, intervalSeconds: 0);

        $this->assertSame(3, $state->probes);
    }

    public function test_zero_tries_still_probes_once(): void
    {
        $state = $this->probe([true]);
        $this->dind($state)->awaitReady(tries: 0, intervalSeconds: 0);

        $this->assertSame(1, $state->probes, 'the default of asking at least once must survive a nonsense argument');
    }

    /**
     * The whole reason this method is not a no-op any more: the account
     * container being up says nothing about the daemon inside it.
     */
    public function test_the_wait_is_declared_on_dind_and_not_left_empty(): void
    {
        $method = new \ReflectionMethod(Dind::class, 'awaitReady');

        $this->assertSame(
            Dind::class,
            $method->getDeclaringClass()->getName(),
            'Dind::awaitReady() must be declared on Dind, not inherited as an empty body'
        );

        $lines = file($method->getFileName()) ?: [];
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $this->assertStringContainsString('innerDockerIsUp', $body, 'the wait must probe the inner daemon');
        $this->assertStringContainsString('accountDaemon()->answers(', (string) file_get_contents($method->getFileName()), 'the probe is a request, not a socket check');
    }
}
