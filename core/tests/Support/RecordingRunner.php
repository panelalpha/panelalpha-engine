<?php

namespace Tests\Support;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System\ProcessRunner;
use Symfony\Component\Process\Process;

/**
 * A ProcessRunner that records what it was asked to run and runs nothing.
 *
 * For a collaborator narrowed to {@see ProcessRunner}: a few lines instead of a
 * subclass of the whole App\System.
 */
final class RecordingRunner implements ProcessRunner
{
    /** @var list<string|array> */
    public array $commands = [];

    /** What every faked command "printed". */
    public string $output = '';

    /**
     * Non-zero by default. ProcessTreeKiller reads `kill -0` this way, so a
     * successful exit would mean "still alive" and it would poll for 5 seconds.
     */
    public int $exitCode = 1;

    private function canned(): Process
    {
        return new class ($this->output, $this->exitCode) extends Process {
            public function __construct(private string $out, private int $code)
            {
                parent::__construct(['true']);
            }

            public function isSuccessful(): bool
            {
                return $this->code === 0;
            }

            public function getExitCode(): ?int
            {
                return $this->code;
            }

            public function getOutput(): string
            {
                return $this->out;
            }

            public function getErrorOutput(): string
            {
                return '';
            }
        };
    }

    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        $this->commands[] = $cmd;

        return '';
    }

    public function execOnHost(string|array $cmd, array $env = []): string
    {
        $this->commands[] = $cmd;

        return '';
    }

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        $this->commands[] = $cmd;

        return $this->canned();
    }

    public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        $this->commands[] = $cmd;

        return $this->canned();
    }

    public function runProcessWithCallbacks(
        string|array $cmd,
        array $env = [],
        int $timeout = 600,
        ?callable $onStart = null,
        ?callable $onOutput = null,
        ?StepWatchdog $watchdog = null
    ): Process {
        $this->commands[] = $cmd;

        return $this->canned();
    }
}
