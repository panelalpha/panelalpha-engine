<?php

namespace Tests\Integration\System\Project\Dind;

use App\Exceptions\BuildStalledException;
use App\Exceptions\DiskLimitException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Privileged;
use App\System\Project\Dind;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ShellOperations;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A step the watchdog stopped for stalling or for the disk limit is logged, stopped inside the account by its
 * tag and rethrown; after the disk limit the account's storage is reclaimed too. The logger is always reset.
 */
class StreamProcessFailedStepTest extends TestCase
{
    /** @var list<string> */
    private array $events = [];

    /** @var list<array{0: string, 1: list<string>, 2: int}> */
    private array $commands = [];

    private ?\Throwable $failWith = null;

    private ?\Throwable $reclaimFails = null;

    public function test_a_stalled_step_is_logged_stopped_by_its_tag_and_rethrown(): void
    {
        config(['deploy.step_idle_timeout' => 900, 'deploy.host_min_free' => 'off']);
        $this->failWith = new BuildStalledException('no output for 900 s');

        $thrown = $this->streamExpectingFailure();

        $this->assertSame($this->failWith, $thrown);
        $tag = $this->streamedTag();
        $this->assertSame([
            'stream', 'flushBuffers', 'error:no output for 900 s', 'stop', 'setPid:null', 'flushBuffers',
        ], $this->events);
        $this->assertSame($this->killArgv($tag), LocalRoot::plain($this->commands[1][1]));
        $this->assertSame(30, $this->commands[1][2]);
    }

    public function test_a_step_over_the_disk_limit_is_stopped_and_the_storage_reclaimed(): void
    {
        config(['deploy.step_idle_timeout' => 900, 'deploy.host_min_free' => 'off']);
        $this->failWith = new DiskLimitException('the account is over its disk limit');

        $thrown = $this->streamExpectingFailure();

        $this->assertSame($this->failWith, $thrown);
        $this->assertSame([
            'stream', 'flushBuffers', 'error:the account is over its disk limit', 'stop', 'reclaim', 'setPid:null', 'flushBuffers',
        ], $this->events);
        $this->assertSame($this->killArgv($this->streamedTag()), LocalRoot::plain($this->commands[1][1]));
    }

    public function test_a_reclaim_that_fails_is_a_warning_and_the_disk_limit_is_still_thrown(): void
    {
        config(['deploy.step_idle_timeout' => 900, 'deploy.host_min_free' => 'off']);
        $this->failWith = new DiskLimitException('the account is over its disk limit');
        $this->reclaimFails = new \RuntimeException('daemon gone');

        $thrown = $this->streamExpectingFailure();

        $this->assertSame($this->failWith, $thrown);
        $this->assertSame([
            'stream', 'flushBuffers', 'error:the account is over its disk limit', 'stop', 'reclaim',
            "warn:Could not reclaim the account's Docker storage: daemon gone", 'setPid:null', 'flushBuffers',
        ], $this->events);
    }

    public function test_any_other_failure_is_rethrown_without_stopping_anything(): void
    {
        config(['deploy.step_idle_timeout' => 900, 'deploy.host_min_free' => 'off']);
        $this->failWith = new \RuntimeException('exec failed');

        $thrown = $this->streamExpectingFailure();

        $this->assertSame($this->failWith, $thrown);
        $this->assertSame(['stream', 'setPid:null', 'flushBuffers'], $this->events);
        $this->assertCount(1, $this->commands);
    }

    public function test_without_a_watchdog_the_step_is_untagged_and_stopped_by_an_empty_tag(): void
    {
        config(['deploy.step_idle_timeout' => 0, 'deploy.host_min_free' => 'off']);
        $this->failWith = new BuildStalledException('stalled');

        $this->streamExpectingFailure();

        $this->assertSame($this->step(), $this->commands[0][1]);
        $this->assertSame($this->killArgv(''), LocalRoot::plain($this->commands[1][1]));
        $this->assertSame(['stream:no-watchdog', 'flushBuffers', 'error:stalled', 'stop', 'setPid:null', 'flushBuffers'], $this->events);
    }

    public function test_a_disk_limit_alone_still_tags_the_step(): void
    {
        config(['deploy.step_idle_timeout' => 0, 'deploy.host_min_free' => '3G']);
        $this->failWith = new DiskLimitException('the engine host is low on disk');

        $this->streamExpectingFailure();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $this->streamedTag());
        $this->assertSame('stream', $this->events[0]);
    }

    public function test_a_step_that_finished_returns_its_process_and_resets_the_logger(): void
    {
        config(['deploy.step_idle_timeout' => 900, 'deploy.host_min_free' => 'off']);

        $process = $this->shell()->streamProcess($this->step(), ['A' => '1'], 120, $this->logger());

        $this->assertTrue($process->isSuccessful());
        $this->assertSame(['stream', 'setPid:null', 'flushBuffers', 'isCancelled'], $this->events);
        $this->assertSame(120, $this->commands[0][2]);
    }

    private function streamExpectingFailure(): \Throwable
    {
        try {
            $this->shell()->streamProcess($this->step(), [], 600, $this->logger());
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('The step did not fail.');
    }

    /** @return list<string> */
    private function step(): array
    {
        return Privileged::accountContainer('acme')->exec(['docker', 'compose', 'build']);
    }

    private function streamedTag(): string
    {
        $environment = LocalRoot::accountEnvironment($this->commands[0][1]);
        $this->assertSame(ShellOperations::STEP_ENV, array_key_first($environment));

        return $environment[ShellOperations::STEP_ENV];
    }

    /** @return list<string> */
    private function killArgv(string $tag): array
    {
        $script = (new \ReflectionClassConstant(ShellOperations::class, 'KILL_TAGGED_SCRIPT'))->getValue();

        return [
            'pa-root', 'account-container.exec', '--', 'acme', '', '', '1', 'DOCKER_CONFIG', '/home/acme/.panelalpha-docker',
            'sh', '-c', $script, 'sh', ShellOperations::STEP_ENV.'='.$tag,
        ];
    }

    private function logger(): DeployLogger
    {
        $logger = Mockery::mock(DeployLogger::class);
        $logger->shouldReceive('flushBuffers')->andReturnUsing(function (): void {
            $this->events[] = 'flushBuffers';
        });
        $logger->shouldReceive('error')->andReturnUsing(function (string $message): void {
            $this->events[] = 'error:'.$message;
        });
        $logger->shouldReceive('warn')->andReturnUsing(function (string $message): void {
            $this->events[] = 'warn:'.$message;
        });
        $logger->shouldReceive('setPid')->andReturnUsing(function (?int $pid): void {
            $this->events[] = 'setPid:'.($pid ?? 'null');
        });
        $logger->shouldReceive('isCancelled')->andReturnUsing(function (): bool {
            $this->events[] = 'isCancelled';

            return false;
        });

        return $logger;
    }

    private function shell(): ShellOperations
    {
        $model = new ModelsUser;
        $model->username = 'acme';
        $model->setDetails(['template' => 'dind', 'UID' => 1000, 'GID' => 1000]);

        $inner = Mockery::mock(InnerDocker::class);
        $inner->shouldReceive('reclaimStorageAfterDiskLimit')->andReturnUsing(function (): void {
            $this->events[] = 'reclaim';
            if ($this->reclaimFails !== null) {
                throw $this->reclaimFails;
            }
        });

        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('system')->andReturn($this->system());
        $dind->shouldReceive('homeDirPath')->andReturn('/home/acme');
        $dind->shouldReceive('username')->andReturn('acme');
        $dind->shouldReceive('userModel')->andReturn($model);
        $dind->shouldReceive('innerDocker')->andReturn($inner);

        return new ShellOperations($dind);
    }

    private function system(): System
    {
        $test = $this;

        return new class ($test) extends System
        {
            public function __construct(private StreamProcessFailedStepTest $test) {}

            public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
            {
                return $this->test->streamed((array) $cmd, $timeout, $watchdog !== null);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return $this->test->ran((array) $cmd, $timeout);
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->test->ran((array) $cmd, $timeout);

                return '';
            }
        };
    }

    /** @param list<string> $cmd */
    public function streamed(array $cmd, int $timeout, bool $watched): Process
    {
        $this->commands[] = ['stream', $cmd, $timeout];
        $this->events[] = $watched ? 'stream' : 'stream:no-watchdog';
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return FakeProcess::ok('');
    }

    /** @param list<string> $cmd */
    public function ran(array $cmd, int $timeout): Process
    {
        $this->commands[] = ['run', $cmd, $timeout];
        $this->events[] = 'stop';

        return FakeProcess::ok('');
    }
}
