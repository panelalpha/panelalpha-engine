<?php

namespace Tests\Support;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System;
use App\System\HostProcess;
use RuntimeException;
use Symfony\Component\Process\Process;

/** A fake host's base: every way System starts a process answers failed instead, so nothing real ever runs. */
abstract class NoProcessHost extends System
{
    public function processes(): HostProcess
    {
        throw new RuntimeException('no process runs in this test');
    }

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        return FakeProcess::failed('no process runs in this test');
    }

    public function runProcessWithCallbacks(
        string|array $cmd,
        array $env = [],
        int $timeout = 600,
        ?callable $onStart = null,
        ?callable $onOutput = null,
        ?StepWatchdog $watchdog = null
    ): Process {
        return FakeProcess::failed('no process runs in this test');
    }
}
