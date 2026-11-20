<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\Inner\HostCommands;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\TestCase;

/**
 * Host work a deploy waits for: run plainly outside a deploy, streamed into the deploy log inside one, and
 * failing with what the command printed; a full disk stops the deploy with the explainer's sentence.
 */
class HostCommandsCancellableTest extends TestCase
{
    private string $username = '';

    /** @var list<array{string, list<string>, int}> how each command was run, its argv and timeout */
    private array $ran = [];

    private ?Process $streamed = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['deploy.step_idle_timeout' => 0, 'deploy.host_min_free' => 'off']);
        $this->username = 'hostcancel' . bin2hex(random_bytes(3));
        $username = $this->username;
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
    }

    public function test_outside_a_deploy_the_command_runs_plainly_with_its_timeout(): void
    {
        $this->commands()->cancellable(['docker', 'build', '-'], 1800);

        $this->assertSame([['exec', ['docker', 'build', '-'], 1800]], $this->ran);
    }

    public function test_inside_a_deploy_the_command_is_streamed_into_the_log(): void
    {
        $this->runningDeploy();
        $this->streamed = FakeProcess::ok("built\n");

        $this->commands()->cancellable(['docker', 'build', '-'], 900);

        $this->assertSame([['stream', ['docker', 'build', '-'], 900]], $this->ran);
    }

    public function test_a_streamed_command_that_fails_throws_its_error_output(): void
    {
        $this->runningDeploy();
        $this->streamed = FakeProcess::of(1, 'partial', 'Error response from daemon: pull access denied');

        $this->expectExceptionMessage('Error response from daemon: pull access denied');

        $this->commands()->cancellable(['docker', 'pull', 'x:1'], 60);
    }

    public function test_a_failure_with_only_standard_output_throws_that(): void
    {
        $this->runningDeploy();
        $this->streamed = FakeProcess::of(2, 'step 3/4 failed');

        $this->expectExceptionMessage('step 3/4 failed');

        $this->commands()->cancellable(['docker', 'build', '-'], 60);
    }

    public function test_a_silent_failure_says_the_host_command_failed(): void
    {
        $this->runningDeploy();
        $this->streamed = FakeProcess::failed();

        $this->expectExceptionMessage('Host Docker command failed');

        $this->commands()->cancellable(['docker', 'build', '-'], 60);
    }

    public function test_a_full_disk_stops_the_deploy_with_the_explained_sentence(): void
    {
        $output = "write /var/lib/docker/tmp/layer: no space left on device\n";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The build ran out of disk space. Free some space in the account or move to a larger plan.');

        $this->commands()->failDeployIfDiskFull($output);
    }

    public function test_any_other_output_does_not_stop_the_deploy(): void
    {
        $this->commands()->failDeployIfDiskFull('Error response from daemon: manifest unknown');

        $this->assertSame([], $this->ran);
    }

    public function test_each_log_level_lands_in_the_deploy_log(): void
    {
        $logger = $this->runningDeploy();
        $commands = $this->commands();

        $commands->logInfo('Fetching base image redis:7');
        $commands->logWarn('Could not refresh redis:7');
        $commands->logDim('Seeding 2 base images at a time');

        $entries = array_values(array_filter(
            $logger->entries(),
            static fn (array $entry): bool => in_array($entry['msg'], ['Fetching base image redis:7', 'Could not refresh redis:7', 'Seeding 2 base images at a time'], true)
        ));
        $this->assertSame(
            [
                [DeployLogger::LEVEL_INFO, 'Fetching base image redis:7'],
                [DeployLogger::LEVEL_WARN, 'Could not refresh redis:7'],
                [DeployLogger::LEVEL_DIM, 'Seeding 2 base images at a time'],
            ],
            array_map(static fn (array $entry): array => [$entry['level'], $entry['msg']], $entries)
        );
    }

    public function test_outside_a_deploy_logging_is_a_no_op(): void
    {
        $commands = $this->commands();

        $commands->logInfo('nobody reads this');
        $commands->logDim('nor this');

        $this->assertNull(DeployLogger::current($this->username));
    }

    private function runningDeploy(): DeployLogger
    {
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);

        return $logger;
    }

    private function commands(): HostCommands
    {
        $test = $this;
        $system = new class($test) extends System
        {
            public function __construct(private readonly HostCommandsCancellableTest $test) {}

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->test->record('exec', (array) $cmd, $timeout);

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                throw new \LogicException('No process may start: ' . implode(' ', (array) $cmd));
            }

            public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
            {
                return $this->test->stream((array) $cmd, $timeout);
            }
        };
        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn($this->username);
        $dind->method('system')->willReturn($system);
        $dind->method('homeDirPath')->willReturn('/home/' . $this->username);
        $dind->method('shell')->willReturnCallback(static fn (): ShellOperations => new ShellOperations($dind));
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('dind')->willReturn($dind);

        return new HostCommands($inner);
    }

    /** @param list<string> $argv */
    public function record(string $how, array $argv, int $timeout): void
    {
        $this->ran[] = [$how, $argv, $timeout];
    }

    /** @param list<string> $argv */
    public function stream(array $argv, int $timeout): Process
    {
        $this->record('stream', $argv, $timeout);

        return $this->streamed ?? FakeProcess::ok();
    }
}
