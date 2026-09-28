<?php

namespace App\System;

use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use Symfony\Component\Process\Process;

/**
 * Running a command, and nothing else.
 *
 * Most of what asks for an App\System only ever runs commands with it, and then
 * carries the paths, the filesystem, MySQL, the webserver and the updater along
 * for the ride. This is the part of System such a collaborator actually needs.
 *
 * Deliberately the same five signatures App\System already has, and App\System
 * implements it: a consumer narrowed to this interface still receives the same
 * object, so the ~34 test files that subclass System and override one of these
 * keep intercepting exactly what they did before. An interface with nicer names
 * would have meant an adapter, and an adapter that did anything other than
 * forward would have moved the interception point -- which is how a suite that
 * means to fake `sudo rm -rf` ends up running it, still green.
 * {@see HostProcess} for the implementation and the reasoning.
 */
interface ProcessRunner
{
    /**
     * @param string|list<string> $cmd
     *
     * @throws DockerErrorException
     * @throws \Exception
     */
    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string;

    /**
     * @param string|list<string> $cmd
     *
     * @throws DockerErrorException
     */
    public function execOnHost(string|array $cmd, array $env = []): string;

    /**
     * @param string|list<string> $cmd
     */
    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process;

    /**
     * @param string|list<string> $cmd
     */
    public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process;

    /**
     * @param string|list<string> $cmd
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
