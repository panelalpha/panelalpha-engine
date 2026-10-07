<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Exceptions\BuildStalledException;
use App\Exceptions\DiskLimitException;
use App\System;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use PHPUnit\Framework\TestCase;

class StepWatchdogTest extends TestCase
{
    private string $pidFile;

    protected function setUp(): void
    {
        $this->pidFile = tempnam(sys_get_temp_dir(), 'watchdog-');
    }

    protected function tearDown(): void
    {
        foreach ($this->pids() as $pid) {
            @posix_kill($pid, 9);
        }
        @unlink($this->pidFile);
    }

    public function test_a_silent_step_is_killed_after_the_limit(): void
    {
        $started = microtime(true);
        try {
            $this->runWatched(['sh', '-c', 'echo "Chromium download 0% of 153.1 Mb"; sleep 30'], 1);
            $this->fail('A silent step was not stopped');
        } catch (BuildStalledException $e) {
            $this->assertStringStartsWith(StepWatchdog::MARKER, $e->getMessage());
            $this->assertStringContainsString('printed nothing for 1s', $e->getMessage());
            $this->assertStringContainsString('Last output: "Chromium download 0% of 153.1 Mb"', $e->getMessage());
            $this->assertSame('build-stalled', DeployFailureExplainer::match($e->getMessage())['rule'] ?? null);
        }
        $this->assertLessThan(8, microtime(true) - $started);
    }

    public function test_a_step_that_keeps_printing_is_not_killed(): void
    {
        $output = '';
        $process = $this->runWatched(
            ['sh', '-c', 'for i in 1 2 3 4 5 6; do echo tick $i; sleep 0.4; done'],
            1,
            function (string $type, string $data) use (&$output): void {
                $output .= $data;
            }
        );

        $this->assertSame(0, $process->getExitCode());
        $this->assertStringContainsString('tick 6', $output);
    }

    public function test_the_whole_process_tree_is_gone_after_the_kill(): void
    {
        $file = escapeshellarg($this->pidFile);
        try {
            $this->runWatched(['sh', '-c', "sleep 30 & echo \$! >> {$file}; setsid sleep 30 & echo \$! >> {$file}; wait"], 1);
            $this->fail('A silent step was not stopped');
        } catch (BuildStalledException) {
        }

        $pids = $this->pids();
        $this->assertCount(2, $pids);
        $deadline = microtime(true) + 3;
        while ($this->alive($pids) !== [] && microtime(true) < $deadline) {
            usleep(100_000);
        }
        $this->assertSame([], $this->alive($pids));
    }

    public function test_the_overall_timeout_still_applies(): void
    {
        $this->expectException(\Symfony\Component\Process\Exception\ProcessTimedOutException::class);
        (new System())->runProcessWithCallbacks(
            ['sh', '-c', 'while true; do echo x; sleep 0.2; done'],
            [],
            1,
            null,
            null,
            new StepWatchdog(5, 'loop')
        );
    }

    /**
     * Warpgate: its last crate compiled silently past the limit and the
     * build was killed a step from the end. Silent but busy is not stalled.
     */
    public function test_a_silent_step_that_is_still_busy_is_not_killed(): void
    {
        $asked = 0;
        $output = '';
        $process = (new System())->runProcessWithCallbacks(
            ['sh', '-c', 'echo "Compiling warpgate v0.29.0"; sleep 3; echo Finished'],
            [],
            60,
            null,
            function (string $type, string $data) use (&$output): void {
                $output .= $data;
            },
            new StepWatchdog(1, 'cargo build', function () use (&$asked): bool {
                $asked++;

                return true;
            })
        );

        $this->assertSame(0, $process->getExitCode());
        $this->assertStringContainsString('Finished', $output);
        $this->assertGreaterThanOrEqual(1, $asked);
    }

    /** One pull took a host from 34 GB free to 0 inside a single step. */
    public function test_a_step_is_stopped_when_the_disk_runs_out_while_it_runs(): void
    {
        $readings = [null, 'the engine host has 2.9G free on /home, under DEPLOY_HOST_MIN_FREE (3G)'];
        $started = microtime(true);
        try {
            (new System())->runProcessWithCallbacks(
                ['sh', '-c', 'while true; do echo pulling; sleep 0.2; done'],
                [],
                60,
                null,
                null,
                new StepWatchdog(0, 'docker compose up', null, function () use (&$readings): ?string {
                    return array_shift($readings);
                }, 1)
            );
            $this->fail('A step that filled the disk was not stopped');
        } catch (DiskLimitException $e) {
            $this->assertSame(
                StepWatchdog::DISK_MARKER . ' (the engine host has 2.9G free on /home, under DEPLOY_HOST_MIN_FREE (3G))',
                $e->getMessage()
            );
            $match = DeployFailureExplainer::match("Deploy failed\n" . $e->getMessage());
            $this->assertSame('disk-limit-reached', $match['rule'] ?? null);
            $this->assertStringContainsString('because the engine host has 2.9G free on /home, under DEPLOY_HOST_MIN_FREE (3G),', $match['message']);
            $this->assertStringContainsString('Free disk on the host', $match['message']);
        }
        $this->assertSame([], $readings, 'asked once per interval');
        $this->assertLessThan(8, microtime(true) - $started);
    }

    public function test_a_disk_reading_that_fails_does_not_stop_the_step(): void
    {
        $process = (new System())->runProcessWithCallbacks(
            ['sh', '-c', 'for i in 1 2 3 4 5 6 7 8; do echo tick; sleep 0.3; done'],
            [],
            60,
            null,
            null,
            new StepWatchdog(0, 'build', null, static function (): ?string {
                throw new \RuntimeException('du: cannot read');
            }, 1)
        );

        $this->assertSame(0, $process->getExitCode());
    }

    public function test_the_project_limit_is_explained_as_the_projects(): void
    {
        $match = DeployFailureExplainer::match(StepWatchdog::DISK_MARKER . ' (the project uses 2.1G, over its 2G disk limit)');

        $this->assertSame('disk-limit-reached', $match['rule'] ?? null);
        $this->assertStringContainsString("Raise the project's disk limit", $match['message']);
    }

    public function test_a_step_that_stops_working_is_killed_and_the_whole_silence_reported(): void
    {
        $answers = [true, true];
        $started = microtime(true);
        try {
            (new System())->runProcessWithCallbacks(
                ['sh', '-c', 'echo "Downloading crates ..."; sleep 30'],
                [],
                60,
                null,
                null,
                new StepWatchdog(1, 'cargo build', function () use (&$answers): bool {
                    return array_shift($answers) ?? false;
                })
            );
            $this->fail('A step that stopped working was not stopped');
        } catch (BuildStalledException $e) {
            // Two busy windows and the one that failed: the silence since the
            // last line, not since the last window.
            $this->assertMatchesRegularExpression('/printed nothing for [34]s/', $e->getMessage());
            $this->assertSame('build-stalled', DeployFailureExplainer::match($e->getMessage())['rule'] ?? null);
        }
        $this->assertLessThan(12, microtime(true) - $started);
    }

    public function test_a_probe_that_cannot_answer_leaves_the_limit_as_it_was(): void
    {
        $this->expectException(BuildStalledException::class);
        (new System())->runProcessWithCallbacks(
            ['sh', '-c', 'sleep 30'],
            [],
            60,
            null,
            null,
            new StepWatchdog(1, 'sleep', function (): bool {
                throw new \RuntimeException('docker stats: no such service');
            })
        );
    }

    /**
     * killTree() polls the process, which delivers what it printed while dying.
     * Otherwise a stalled exec is reported as "printed nothing for 0s" after
     * 21s of silence.
     */
    public function test_what_the_step_prints_while_being_killed_does_not_reset_the_report(): void
    {
        try {
            (new System())->runProcessWithCallbacks(
                ['sh', '-c', 'trap "echo terminated; exit 143" TERM; echo "Downloading crates ..."; sleep 30 & wait'],
                [],
                60,
                null,
                null,
                new StepWatchdog(2, 'cargo build', fn (): bool => false)
            );
            $this->fail('A silent step was not stopped');
        } catch (BuildStalledException $e) {
            $this->assertStringContainsString('printed nothing for 2s', $e->getMessage());
            $this->assertStringContainsString('Last output: "Downloading crates ..."', $e->getMessage());
        }
    }

    public function test_the_explainer_names_the_step_the_silence_and_the_last_line(): void
    {
        $message = (new StepWatchdog(900, 'docker compose up -d --build'))->message(900);
        $match = DeployFailureExplainer::match("#12 5.1 noise\n" . $message . "\nno space left on device");

        $this->assertSame('build-stalled', $match['rule'] ?? null);
        $this->assertStringContainsString('"docker compose up -d --build"', $match['message']);
        $this->assertStringContainsString('15 minutes', $match['message']);
        $this->assertStringContainsString('Last output: (none)', $match['message']);
    }

    /**
     * @param list<string> $cmd
     */
    private function runWatched(array $cmd, int $idle, ?callable $onOutput = null): \Symfony\Component\Process\Process
    {
        return (new System())->runProcessWithCallbacks(
            $cmd,
            [],
            60,
            null,
            $onOutput,
            new StepWatchdog($idle, implode(' ', $cmd))
        );
    }

    /** @return list<int> */
    private function pids(): array
    {
        $lines = @file($this->pidFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_map('intval', $lines);
    }

    /**
     * @param list<int> $pids
     * @return list<int>
     */
    private function alive(array $pids): array
    {
        return array_values(array_filter($pids, static function (int $pid): bool {
            $stat = @file_get_contents("/proc/{$pid}/stat");

            return is_string($stat) && !str_contains(substr($stat, (int) strrpos($stat, ')')), ') Z ');
        }));
    }
}
