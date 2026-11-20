<?php

namespace Tests\Integration\System;

use App\System;
use App\System\Projects;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PushRollbackDirTest extends TestCase
{
    public function test_the_engine_process_creates_it_private_with_no_root_step(): void
    {
        $system = $this->localSystem();
        $dir = $this->tempRoot() . '/push-rollback/live';

        (new Projects($system))->prepareRollbackDir($dir);

        $this->assertDirectoryExists($dir);
        $this->assertSame('0700', substr(sprintf('%o', fileperms($dir)), -4));
        $this->assertSame([], $system->commands);
    }

    public function test_it_refuses_a_rollback_directory_that_is_a_symlink(): void
    {
        $root = $this->tempRoot();
        mkdir($root . '/outside', 0777, true);
        symlink($root . '/outside', $root . '/live');
        $system = $this->localSystem();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('it is a symlink');

        (new Projects($system))->prepareRollbackDir($root . '/live');
    }

    private function tempRoot(): string
    {
        $root = sys_get_temp_dir() . '/pa-rollback-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);

        return $root;
    }

    private function localSystem(): System
    {
        return new class extends System {
            /** @var list<list<string>> */
            public array $commands = [];

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = is_array($cmd) ? array_values($cmd) : [$cmd];

                return new Process(['true']);
            }
        };
    }
}
