<?php

namespace App\System;

use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System as EngineSystem;
use Exception;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Laravel\Prompts\Output\ConsoleOutput;
use Symfony\Component\Process\Process;

/**
 * Running commands: on this container, or in the host's namespaces.
 *
 * Everything the engine does to a machine goes through here -- roughly 290 call
 * sites reach it as `$system->exec(...)` and friends.
 *
 * **The delegation chain is load-bearing.** About thirty test files subclass
 * App\System and override one or more of exec(), execOnHost(), runProcess() and
 * runProcessOnHost() so that a test never runs a real command. Some override
 * only exec(), some only runProcess(). That works because System's methods call
 * each other virtually:
 *
 *     execOnHost() -> exec() -> runProcess()
 *     runProcessOnHost()     -> runProcess()
 *
 * so this class calls back through the System instance rather than into itself.
 * Short-circuiting any of those hops would make overrides stop taking effect --
 * and a test that meant to fake `sudo rm -rf` would run it.
 * {@see \Tests\Unit\System\HostProcessTest}
 */
final class HostProcess
{
    /** Entering the host's namespaces from inside the engine container. */
    private const NSENTER = ['sudo', 'nsenter', '--target', '1', '--all'];

    public function __construct(private readonly EngineSystem $system)
    {
    }

    /**
     * The same command, run on the host instead.
     *
     * A list stays a list and a string stays a shell line: the caller chose
     * which, and turning one into the other would change how it is quoted.
     *
     * @param string|list<string> $cmd
     *
     * @return string|list<string>
     */
    public static function onHost(string|array $cmd): string|array
    {
        return is_string($cmd)
            ? implode(' ', self::NSENTER) . ' ' . $cmd
            : [...self::NSENTER, ...$cmd];
    }

    /**
     * Run to completion and return stdout, or throw.
     *
     * A docker daemon error is its own exception because the HTTP boundary
     * reads it as 422 or 503 rather than 500. {@see DockerErrorException}
     *
     * @param string|list<string> $cmd
     *
     * @throws DockerErrorException
     * @throws Exception
     */
    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        // Through the System instance on purpose: see the class docblock.
        $process = $this->system->runProcess($cmd, $env, $timeout);

        if ($process->isSuccessful()) {
            return $process->getOutput();
        }

        $message = $process->getErrorOutput() ?: $process->getOutput();

        if (
            Str::contains($message, 'Error response from daemon')
            || DockerErrorException::meansContainerUnavailable($message)
        ) {
            throw new DockerErrorException($message);
        }

        throw new Exception($message);
    }

    /**
     * @param string|list<string> $cmd
     */
    public function run(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        $process = self::make($cmd);
        $process->setTimeout($timeout);

        if (!self::isDebugging()) {
            $process->run(null, $env);

            return $process;
        }

        $output = new ConsoleOutput();
        $output->writeln('<comment>Running process: ' . (is_array($cmd) ? implode(' ', $cmd) : $cmd) . '</comment>');
        $process->run(function (string $type, string $data) use ($output): void {
            $output->writeln($type === Process::OUT ? "<info>{$data}</info>" : "<comment>{$data}</comment>");
        }, $env);
        $output->writeln('<comment>Process exited with code: ' . (string) $process->getExitCode() . '</comment>');

        return $process;
    }

    /**
     * Start and wait, with the caller watching the output as it arrives. The
     * watchdog, when there is one, is what kills a step that has gone quiet.
     *
     * @param string|list<string> $cmd
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
     * @param string|list<string> $cmd
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
        if (!App::runningInConsole() || empty($_SERVER['argv'])) {
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
