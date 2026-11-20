<?php

namespace Tests\Integration\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** The kill falls back to SIGKILL for what ignores SIGTERM, and a process that never started needs no kill. */
class StepWatchdogKillTreeTest extends TestCase
{
    private string $pidFile;

    protected function setUp(): void
    {
        $this->pidFile = sys_get_temp_dir() . '/watchdog-kill-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $pid = (int) @file_get_contents($this->pidFile);
        if ($pid > 0) {
            @posix_kill($pid, 9);
        }
        @unlink($this->pidFile);
    }

    public function test_a_process_that_never_started_is_left_alone(): void
    {
        $process = new Process(['true']);

        StepWatchdog::killTree($process);

        $this->assertFalse($process->isStarted());
    }

    public function test_a_child_that_ignores_sigterm_is_killed_after_the_grace_period(): void
    {
        $file = escapeshellarg($this->pidFile);
        $process = new Process(['sh', '-c', "sh -c 'trap \"\" TERM; echo \$\$ > {$file}; exec sleep 60' & wait"]);
        $process->start();
        $deadline = microtime(true) + 5;
        while ((int) @file_get_contents($this->pidFile) === 0 && microtime(true) < $deadline) {
            usleep(50_000);
        }
        $child = (int) file_get_contents($this->pidFile);
        $this->assertGreaterThan(0, $child);

        $started = microtime(true);
        StepWatchdog::killTree($process);

        $this->assertGreaterThanOrEqual(4.5, microtime(true) - $started);
        $this->assertFalse($process->isRunning());
        $this->assertFalse($this->alive($child));
    }

    private function alive(int $pid): bool
    {
        $deadline = microtime(true) + 3;
        do {
            $stat = @file_get_contents("/proc/{$pid}/stat");
            if (! is_string($stat) || str_contains(substr($stat, (int) strrpos($stat, ')')), ') Z ')) {
                return false;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        return true;
    }
}
