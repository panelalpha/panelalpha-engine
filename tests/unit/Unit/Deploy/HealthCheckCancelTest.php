<?php

namespace Tests\Unit\Deploy;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A cancel stops the new container at once, after which the final health
 * check only ever reads "nothing answered" and used to wait out every attempt,
 * about a minute, before the deploy could end as cancelled.
 */
class HealthCheckCancelTest extends TestCase
{
    private string $username = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'hccancel' . bin2hex(random_bytes(3));
        $username = $this->username;
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
    }

    public function test_a_cancel_during_the_final_health_check_stops_it_at_once(): void
    {
        DeployLogger::start($this->username)->stage(DeployLogger::STAGE_RUNNING);
        $health = $this->health($this->cancellingSystem());

        $started = microtime(true);
        try {
            $health->report();
            $this->fail('the cancelled deploy went on past the health check');
        } catch (DeployCancelledException) {
        }

        $this->assertLessThan(10, microtime(true) - $started);
    }

    private function cancellingSystem(): System
    {
        $username = $this->username;

        return new class ($username) extends System {
            public function __construct(private string $username)
            {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
            {
                $process = new Process(['sleep', '30']);
                $process->setTimeout($timeout);
                $process->start();
                DeployLogger::requestCancel($this->username);
                if ($onStart !== null) {
                    $onStart($process);
                }
                $process->wait();

                return $process;
            }
        };
    }

    private function health(System $system): AppHealth
    {
        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn($this->username);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        return new class ($dind) extends AppHealth {
            public function ports(): array
            {
                return [8080];
            }

            protected function containerSnapshot(): ?string
            {
                return null;
            }
        };
    }
}
