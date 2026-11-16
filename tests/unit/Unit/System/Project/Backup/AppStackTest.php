<?php

namespace Tests\Unit\System\Project\Backup;

use App\System;
use App\System\Project;
use App\System\Project\Backup\AppStack;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\ShellOperations;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A backup or a restore stops the inner stack and starts again what it stopped, whether or not the project has a
 * recorded deploy strategy: a container project created without a source runs the engine's bootstrap compose.
 */
class AppStackTest extends TestCase
{
    /** @var list<list<string>> */
    private array $composeCommands = [];

    /** @var list<array{Id: string}> */
    private array $running = [];

    private bool $listingFails = false;

    public function test_a_stack_without_a_recorded_strategy_is_started_again(): void
    {
        $this->running = [['Id' => 'app1']];
        $stack = new AppStack($this->project());

        $stack->stop();
        $stack->start();

        $this->assertSame([['stop'], ['start']], $this->composeCommands);
    }

    public function test_a_stack_that_ran_nothing_is_not_started(): void
    {
        $stack = new AppStack($this->project());

        $stack->stop();
        $stack->start();

        $this->assertSame([['stop']], $this->composeCommands);
    }

    public function test_a_daemon_that_cannot_list_the_stack_counts_as_running(): void
    {
        $this->listingFails = true;
        $stack = new AppStack($this->project());

        $stack->stop();
        $stack->start();

        $this->assertSame([['stop'], ['start']], $this->composeCommands);
    }

    public function test_a_start_without_a_stop_starts_nothing(): void
    {
        $this->running = [['Id' => 'app1']];

        (new AppStack($this->project()))->start();

        $this->assertSame([], $this->composeCommands);
    }

    private function project(): Project
    {
        $test = $this;
        $system = new class($test) extends System
        {
            public function __construct(private AppStackTest $test) {}

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return $this->test->answer(array_values((array) $cmd));
            }
        };

        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn('alice');
        $dind->method('homeDirPath')->willReturn('/home/alice');
        $dind->method('userAppComposeCommand')->willReturnCallback(
            static fn (array $rest): array => ['docker', 'compose', ...$rest]
        );
        $dind->method('userAppComposeProjectName')->willReturn('project');
        $dind->method('accountDaemon')->willReturnCallback(static fn (): AccountDaemon => new AccountDaemon($dind));
        $dind->method('shell')->willReturnCallback(static fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('app')->willReturn(null);

        $project = $this->createStub(Project::class);
        $project->method('runtime')->willReturn($dind);
        $project->method('username')->willReturn('alice');

        return $project;
    }

    /** @param list<string> $argv */
    public function answer(array $argv): string
    {
        if (LocalRoot::action($argv) === 'account-docker.listContainers') {
            if ($this->listingFails) {
                throw new \RuntimeException('Cannot connect to the Docker daemon');
            }

            return (string) json_encode($this->running);
        }

        $compose = LocalRoot::composeCommand($argv);
        if ($compose !== []) {
            $this->composeCommands[] = array_values(array_intersect($compose, ['stop', 'start']));
        }

        return '';
    }
}
