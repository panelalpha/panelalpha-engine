<?php

namespace Tests\Integration\System;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System;
use App\System\EngineUpdate;
use App\System\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\TestCase;

/** A placeholder whose start is not a timestamp is treated as long past its grace. */
class EngineUpdatePendingStampTest extends TestCase
{
    public function test_a_placeholder_with_an_unreadable_start_is_not_running(): void
    {
        $dir = EngineUpdate::LOGS_DIR . '/latest';
        $system = new class ($dir) extends System {
            /** @var list<string|list<string>> */
            public array $commands = [];

            public function __construct(private string $dir)
            {
            }

            public function filesystem(): Filesystem
            {
                return new class ($this->dir) extends Filesystem {
                    public function __construct(private string $dir)
                    {
                    }

                    public function directoryExists(string $path): bool
                    {
                        return $path === $this->dir;
                    }

                    public function cat(string $path): ?string
                    {
                        return $path === $this->dir . '/' . EngineUpdate::PENDING_FILE ? "seeded-by-hand\n" : null;
                    }
                };
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null,
            ): Process {
                return $this->runProcess($cmd, $env, $timeout);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = $cmd;

                return FakeProcess::ok();
            }
        };

        $this->assertFalse((new EngineUpdate($system))->isRunning());
        $this->assertSame([], $system->commands);
    }
}
