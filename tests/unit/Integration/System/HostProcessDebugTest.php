<?php

namespace Tests\Integration\System;

use App\System;
use Tests\TestCase;

/** `artisan ... -v` echoes each command it runs; anything else runs it quietly. */
class HostProcessDebugTest extends TestCase
{
    /** @var list<string> */
    private array $argv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->argv = $_SERVER['argv'];
    }

    protected function tearDown(): void
    {
        $_SERVER['argv'] = $this->argv;
        parent::tearDown();
    }

    public function test_a_verbose_artisan_run_still_returns_the_commands_output(): void
    {
        $_SERVER['argv'] = ['artisan', 'project:deploy', '-v'];

        $process = (new System())->runProcess(['sh', '-c', 'echo out; echo err >&2; exit 3']);

        $this->assertSame("out\n", $process->getOutput());
        $this->assertSame("err\n", $process->getErrorOutput());
        $this->assertSame(3, $process->getExitCode());
    }

    public function test_a_run_without_arguments_is_not_verbose(): void
    {
        $_SERVER['argv'] = [];

        $this->assertSame("quiet\n", (new System())->exec(['echo', 'quiet']));
    }
}
