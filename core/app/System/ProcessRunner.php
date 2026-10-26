<?php

namespace App\System;

use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use Symfony\Component\Process\Process;

/**
 * Running a command, and nothing else: the part of App\System most of its
 * collaborators need.
 *
 * The same five signatures App\System has, and App\System implements it, so a
 * test that subclasses System and overrides one of them still intercepts every
 * command a narrowed consumer runs. {@see HostProcess} for the implementation.
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
     * @param  string|list<string>  $cmd
     *
     * @throws DockerErrorException
     */
    public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string;

    /**
     * @param  string|list<string>  $cmd
     */
    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process;

    /**
     * @param  string|list<string>  $cmd
     */
    public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process;

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
