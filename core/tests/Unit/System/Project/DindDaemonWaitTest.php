<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

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
    /** What each `docker info` probe reports, and how many were made. */
    private function probe(array $answers): \stdClass
    {
        $state = new \stdClass();
        $state->answers = $answers;
        $state->probes = 0;
        $state->commands = [];

        return $state;
    }

    /**
     * A Dind whose real ShellOperations dispatches into a canned host System:
     * the wrap and the argv are the production ones, only the host is replaced.
     *
     * ShellOperations is final, so nothing about it is subclassed here -- only
     * the Dind it is handed, which is what the wait actually talks to.
     */
    private function dind(\stdClass $state): Dind
    {
        $host = new class ($state) extends System {
            public function __construct(private \stdClass $state)
            {
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->state->probes++;
                $this->state->commands[] = is_array($cmd) ? implode(' ', $cmd) : $cmd;

                $answers = $this->state->answers;
                $up = $answers[$this->state->probes - 1] ?? (end($answers) ?: false);

                $process = Process::fromShellCommandline($up ? 'exit 0' : 'exit 1');
                $process->run();

                return $process;
            }
        };

        $account = new class ($host) extends Dind {
            public function __construct(private System $host)
            {
                // The parent constructor needs the project aggregate, which
                // neither wrap() nor the wait touches with asUser left off.
            }

            public function system(): System
            {
                return $this->host;
            }

            public function composeFilePath(): string
            {
                return '/opt/panelalpha/shared-hosting/users/test/docker-compose.yml';
            }

            public function username(): string
            {
                return 'test';
            }

            public function userModel(): ModelsUser
            {
                throw new \LogicException('the probe must not run as a user');
            }
        };

        $shell = new ShellOperations($account);

        return new class ($shell) extends Dind {
            public function __construct(private ShellOperations $standIn)
            {
            }

            public function shell(): ShellOperations
            {
                return $this->standIn;
            }
        };
    }

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
     * It asks `docker info` inside the account, and not `test -S` on the socket
     * file: the file exists before the daemon behind it can serve a request,
     * and the request is what the failing commands actually make.
     */
    public function test_the_probe_is_a_request_inside_the_account(): void
    {
        $state = $this->probe([true]);
        $this->dind($state)->awaitReady(tries: 1, intervalSeconds: 0);

        $this->assertCount(1, $state->commands);
        $command = $state->commands[0];
        $this->assertStringContainsString('docker info --format', $command);
        $this->assertStringNotContainsString('test -S', $command);
        $this->assertStringContainsString('docker compose', $command, 'the request runs through the account, not on the host');
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
        $this->assertStringContainsString('docker info', (string) file_get_contents($method->getFileName()), 'the probe is a request, not a socket check');
    }
}
