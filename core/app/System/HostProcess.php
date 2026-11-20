<?php

namespace App\System;

use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System as EngineSystem;
use Exception;
use Illuminate\Support\Facades\App;
use Laravel\Prompts\Output\ConsoleOutput;
use Symfony\Component\Process\Process;
use Tests\Integration\System\HostProcessTest;

/**
 * Runs commands here, or a gateway-built command in the host's namespaces ({@see Privileged::onHost()}).
 * Calls go back through System, where tests override execOnHost(), exec() and runProcess().
 *
 * @see HostProcessTest
 */
final class HostProcess
{
    public function __construct(private readonly EngineSystem $system) {}

    /**
     * Run to completion and return stdout, or throw. A docker daemon error is its own exception because the HTTP
     * boundary reads it as 422 or 503 rather than 500. {@see DockerErrorException}
     *
     * @param  string|list<string>  $cmd
     *
     * @throws DockerErrorException
     * @throws Exception
     */
    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        $process = $this->system->runProcess($cmd, $env, $timeout);

        if ($process->isSuccessful()) {
            return $process->getOutput();
        }

        throw DockerErrorException::orPlainFailure($process->getErrorOutput() ?: $process->getOutput());
    }

    /**
     * @param  string|list<string>  $cmd
     */
    public function run(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        $process = self::make($cmd);
        $process->setTimeout($timeout);

        if (! self::isDebugging()) {
            $process->run(null, $env);

            return $process;
        }

        $output = new ConsoleOutput;
        $output->writeln('<comment>Running process: '.(is_array($cmd) ? implode(' ', $cmd) : $cmd).'</comment>');
        $process->run(function (string $type, string $data) use ($output): void {
            $output->writeln($type === Process::OUT ? "<info>{$data}</info>" : "<comment>{$data}</comment>");
        }, $env);
        $output->writeln('<comment>Process exited with code: '.(string) $process->getExitCode().'</comment>');

        return $process;
    }

    /**
     * Start and wait, with the caller watching the output as it arrives. The
     * watchdog, when there is one, is what kills a step that has gone quiet.
     *
     * @param  string|list<string>  $cmd
     */
    public function runWithCallbacks(
        string|array $cmd,
        array $env = [],
        int $timeout = 600,
        ?callable $onStart = null,
        ?callable $onOutput = null,
        ?StepWatchdog $watchdog = null,
    ): Process {
        $process = self::make($cmd);
        $process->setTimeout($timeout);

        $process->start($watchdog?->watch($onOutput), $env);

        if ($onStart !== null) {
            $onStart($process);
        }

        $watchdog !== null ? $watchdog->wait($process) : $process->wait($onOutput);

        return $process;
    }

    /**
     * A list is an argv and is not touched by a shell; a string is a shell line.
     *
     * @param  string|list<string>  $cmd
     */
    private static function make(string|array $cmd): Process
    {
        return is_array($cmd)
            ? new Process(array_values($cmd))
            : Process::fromShellCommandline($cmd);
    }

    /** `artisan ... -v` echoes every command and its output. */
    private static function isDebugging(): bool
    {
        if (! App::runningInConsole() || empty($_SERVER['argv'])) {
            return false;
        }

        foreach ($_SERVER['argv'] as $arg) {
            if (in_array($arg, ['-v', '-vv', '-vvv'], true)) {
                return true;
            }
        }

        return false;
    }
}
