<?php

namespace Tests\Unit\Task;

use App\System;
use App\Lib\Task\ProcessTreeKiller;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProcessTreeKillerTest extends TestCase
{
    public function test_kill_sends_term_to_pid_and_skips_kill_when_already_dead(): void
    {
        $ps = Mockery::mock(Process::class);
        $ps->shouldReceive('getOutput')->andReturn("");

        $term = Mockery::mock(Process::class);

        $aliveCheck = Mockery::mock(Process::class);
        $aliveCheck->shouldReceive('getExitCode')->andReturn(1);

        $system = Mockery::mock(System::class);
        $system->shouldReceive('runProcess')
            ->once()
            ->with(['ps', '-o', 'pid=', '--ppid', '4242'])
            ->andReturn($ps);
        $system->shouldReceive('runProcess')
            ->once()
            ->with('kill -TERM 4242 2>/dev/null; true')
            ->andReturn($term);
        $system->shouldReceive('runProcess')
            ->once()
            ->with('kill -0 4242 2>/dev/null')
            ->andReturn($aliveCheck);

        (new ProcessTreeKiller($system))->kill(4242);
    }
}
