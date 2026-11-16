<?php

namespace App\System;

use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use Symfony\Component\Process\Process;

/**
 * Running a command, and nothing else: App\System's own five signatures, which it implements, so a test that
 * overrides one in a System subclass still intercepts a narrowed consumer's commands. {@see HostProcess} runs them.
 */
interface ProcessRunner
{
    /**
     * @param  string|list<string>  $cmd
     *
     * @throws DockerErrorException
     * @throws \Exception
     */
    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string;

    /**
     * A command the gateway built, in the host's namespaces.
     *
     * @param  list<string>  $cmd
     *
     * @throws DockerErrorException
     */
    public function execOnHost(array $cmd, array $env = [], int $timeout = 600): string;

    /**
     * @param  string|list<string>  $cmd
     */
    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process;

    /**
     * A command the gateway built, in the host's namespaces.
     *
     * @param  list<string>  $cmd
     */
    public function runProcessOnHost(array $cmd, array $env = [], int $timeout = 600): Process;

    /**
     * @param  string|list<string>  $cmd
     */
    public function runProcessWithCallbacks(
        string|array $cmd,
        array $env = [],
        int $timeout = 600,
        ?callable $onStart = null,
        ?callable $onOutput = null,
        ?StepWatchdog $watchdog = null
    ): Process;
}
