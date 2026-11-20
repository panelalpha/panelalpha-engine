<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\ShellOperations;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\TestCase;

/**
 * A probe of an account whose container is down: docker ran nothing, so the report names the stopped container,
 * inside a deploy (its cancel can stop the probe) and outside one alike, rather than a probe that failed.
 */
class AppHealthStoppedAccountTest extends TestCase
{
    private string $username = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'hlstop-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    /** @return array<string, array{bool}> */
    public static function probes(): array
    {
        return [
            'outside a deploy' => [false],
            'inside a deploy' => [true],
        ];
    }

    #[DataProvider('probes')]
    public function test_a_stopped_account_container_is_named_as_not_running(bool $inDeploy): void
    {
        $this->assertSame(
            [
                'healthy' => false,
                'serving' => 'stopped',
                'ports' => [],
                'error' => 'the project\'s container is not running, so nothing was probed (service "dind" is not running)',
            ],
            $this->probedWith("service \"dind\" is not running\n", $inDeploy),
        );
    }

    #[DataProvider('probes')]
    public function test_a_probe_that_failed_on_its_own_still_says_it_could_not_run(bool $inDeploy): void
    {
        $this->assertSame(
            [
                'healthy' => false,
                'ports' => [],
                'error' => 'could not run the probe: bash: curl: command not found',
            ],
            $this->probedWith("bash: curl: command not found\n", $inDeploy),
        );
    }

    /** @return array<string, mixed> */
    private function probedWith(string $failure, bool $inDeploy): array
    {
        $logger = $inDeploy ? DeployLogger::start($this->username) : null;
        try {
            return $this->health(new FailingHost($failure))->check(3, 1, 1, false, $logger);
        } finally {
            $logger?->finish(DeployLogger::STATUS_FAILED, 'done');
        }
    }

    private function health(System $system): AppHealth
    {
        $user = $this->createStub(User::class);
        $user->method('getDetails')->willReturn(['app_port' => 8000]);
        $user->method('getAppPort')->willReturn(8000);
        $user->method('getMainDomain')->willThrowException(new \RuntimeException('no database'));

        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('username')->andReturn($this->username);
        $dind->shouldReceive('composeFilePath')->andReturn('/opt/panelalpha/shared-hosting/users/' . $this->username . '/docker-compose.yml');
        $dind->shouldReceive('homeDirPath')->andReturn('/home/' . $this->username);
        $dind->shouldReceive('userModel')->andReturn($user);
        $dind->shouldReceive('system')->andReturn($system);
        $dind->shouldReceive('shell')->andReturnUsing(fn (): ShellOperations => new ShellOperations($dind));

        return new class ($dind) extends AppHealth {
            public function ports(): array
            {
                return [8000];
            }
        };
    }
}

/** @internal Every command fails with the output it was given, on stderr, as docker prints it. */
final class FailingHost extends System
{
    public function __construct(private readonly string $output)
    {
    }

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        return FakeProcess::failed($this->output);
    }

    public function runProcessWithCallbacks(
        string|array $cmd,
        array $env = [],
        int $timeout = 600,
        ?callable $onStart = null,
        ?callable $onOutput = null,
        ?StepWatchdog $watchdog = null
    ): Process {
        return FakeProcess::failed($this->output);
    }
}
