<?php

namespace Tests\Support;

use App\System;
use Symfony\Component\Process\Process;

/**
 * A host System that records what it was asked to run and answers with one
 * canned process, so a command line can be asserted without Docker.
 */
class RecordingSystem extends System
{
    /** @var list<array|string> */
    public array $commands = [];

    public string $output = '';

    public int $exitCode = 0;

    public function engineDirPath(): string
    {
        return '/opt/panelalpha/shared-hosting';
    }

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        $this->commands[] = $cmd;

        return $this->exitCode === 0
            ? FakeProcess::ok($this->output)
            : FakeProcess::failed($this->output);
    }

    /** The shell script of the first recorded `bash -c` call. */
    public function firstScript(): string
    {
        $command = $this->commands[0] ?? '';

        return is_array($command) ? (string) ($command[2] ?? '') : $command;
    }
}
