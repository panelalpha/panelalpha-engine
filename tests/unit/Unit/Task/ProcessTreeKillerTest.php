<?php

namespace Tests\Unit\Task;

use App\System;
use App\Lib\Task\ProcessTreeKiller;
use App\System\ProcessRunner;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\Support\HostCommand;
use Tests\TestCase;

class ProcessTreeKillerTest extends TestCase
{
    public function test_kill_sends_term_to_pid_and_skips_kill_when_already_dead(): void
    {
        $ps = Mockery::mock(Process::class);
        $ps->shouldReceive('getOutput')->andReturn("");

        $term = Mockery::mock(Process::class);

        $aliveCheck = Mockery::mock(Process::class);
        $aliveCheck->shouldReceive('getExitCode')->andReturn(1);

        $system = Mockery::mock(System::class);
        $system->shouldReceive('runProcess')
            ->once()
            ->with(['ps', '-o', 'pid=', '--ppid', '4242'])
            ->andReturn($ps);
        $system->shouldReceive('runProcess')
            ->once()
            ->with('kill -TERM 4242 2>/dev/null; true')
            ->andReturn($term);
        $system->shouldReceive('runProcess')
            ->once()
            ->with('kill -0 4242 2>/dev/null')
            ->andReturn($aliveCheck);

        (new ProcessTreeKiller($system))->kill(4242);
    }

    public function test_every_descendant_is_found_once_and_terminated_with_the_parent(): void
    {
        $runner = $this->scripted(
            ['100' => "101\n102\n", '101' => "103\n\n0\n", '103' => "101\n"],
            static fn (int $pid, int $round): int => 1,
        );

        (new ProcessTreeKiller($runner))->kill(100);

        $this->assertSame([
            ['ps', '-o', 'pid=', '--ppid', '100'],
            ['ps', '-o', 'pid=', '--ppid', '101'],
            ['ps', '-o', 'pid=', '--ppid', '102'],
            ['ps', '-o', 'pid=', '--ppid', '103'],
            'kill -TERM 101 102 103 100 2>/dev/null; true',
            'kill -0 101 2>/dev/null',
            'kill -0 102 2>/dev/null',
            'kill -0 103 2>/dev/null',
            'kill -0 100 2>/dev/null',
        ], $runner->commands);
    }

    public function test_a_process_that_dies_after_one_check_is_not_killed(): void
    {
        $runner = $this->scripted([], static fn (int $pid, int $round): int => $round === 1 ? 0 : 1);

        (new ProcessTreeKiller($runner))->kill(200);

        $this->assertSame([
            ['ps', '-o', 'pid=', '--ppid', '200'],
            'kill -TERM 200 2>/dev/null; true',
            'kill -0 200 2>/dev/null',
            'kill -0 200 2>/dev/null',
        ], $runner->commands);
    }

    public function test_what_is_still_alive_after_the_grace_period_is_killed(): void
    {
        $runner = $this->scripted(['300' => "301\n"], static fn (int $pid, int $round): int => $pid === 301 ? 0 : 1);

        (new ProcessTreeKiller($runner))->kill(300);

        $this->assertSame('kill -KILL 301 2>/dev/null; true', end($runner->commands));
        $checks = array_count_values(array_filter($runner->commands, 'is_string'));
        $this->assertGreaterThan(1, $checks['kill -0 301 2>/dev/null']);
        $this->assertSame($checks['kill -0 301 2>/dev/null'], $checks['kill -0 300 2>/dev/null']);
        $this->assertSame(1, $checks['kill -TERM 301 300 2>/dev/null; true']);
    }

    /**
     * @param  array<string, string>  $children  ps output per parent pid
     * @param  \Closure(int, int): int  $exitOfCheck  `kill -0` exit code per pid and round
     */
    private function scripted(array $children, \Closure $exitOfCheck): ProcessRunner
    {
        return new class ($children, $exitOfCheck) implements ProcessRunner {
            /** @var list<string|array> */
            public array $commands = [];

            /** @var array<int, int> */
            private array $rounds = [];

            public function __construct(private array $children, private \Closure $exitOfCheck) {}

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = $cmd;
                if (is_array($cmd)) {
                    return $this->process($this->children[$cmd[4]] ?? '', 0);
                }
                if (preg_match('/^kill -0 (\d+) /', $cmd, $m) === 1) {
                    $pid = (int) $m[1];
                    $this->rounds[$pid] = ($this->rounds[$pid] ?? 0) + 1;

                    return $this->process('', ($this->exitOfCheck)($pid, $this->rounds[$pid]));
                }

                return $this->process('', 0);
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                throw new \LogicException('not used');
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = HostCommand::argv($cmd);
                throw new \LogicException('not used');
            }

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $cmd = HostCommand::argv($cmd);
                throw new \LogicException('not used');
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?\App\Lib\Deploy\DeployLog\StepWatchdog $watchdog = null
            ): Process {
                throw new \LogicException('not used');
            }

            private function process(string $output, int $exit): Process
            {
                $process = Mockery::mock(Process::class);
                $process->shouldReceive('getOutput')->andReturn($output);
                $process->shouldReceive('getExitCode')->andReturn($exit);

                return $process;
            }
        };
    }
}
