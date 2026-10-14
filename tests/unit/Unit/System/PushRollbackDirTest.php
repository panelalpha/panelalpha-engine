<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Projects;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PushRollbackDirTest extends TestCase
{
    public function test_it_creates_the_rollback_directory_for_the_engine_process(): void
    {
        $system = $this->localSystem();
        $dir = $this->tempRoot() . '/.pre-push-db';

        (new Projects($system))->prepareRollbackDir($dir);

        $this->assertDirectoryExists($dir);
        $this->assertSame([
            ['sudo', 'test', '-L', $dir],
            ['sudo', 'mkdir', '-p', $dir],
            ['sudo', 'chown', $this->engineUser(), $dir],
            ['sudo', 'chmod', '0700', $dir],
        ], $system->commands);
    }

    public function test_it_refuses_a_rollback_directory_that_is_a_symlink(): void
    {
        $root = $this->tempRoot();
        mkdir($root . '/outside', 0777, true);
        symlink($root . '/outside', $root . '/.pre-push-db');
        $system = $this->localSystem();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('it is a symlink');

        (new Projects($system))->prepareRollbackDir($root . '/.pre-push-db');
    }

    private function engineUser(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $entry = posix_getpwuid(posix_geteuid());
            if (is_array($entry) && is_string($entry['name'] ?? null)) {
                return $entry['name'];
            }
        }

        return 'www-data';
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
                $argv = is_array($cmd) ? array_values($cmd) : (preg_split('/\s+/', trim($cmd)) ?: []);
                $this->commands[] = $argv;
                $exit = 0;

                if (($argv[0] ?? '') === 'sudo' && ($argv[1] ?? '') === 'test') {
                    $exit = match ($argv[2] ?? '') {
                        '-d' => is_dir($argv[3] ?? '') ? 0 : 1,
                        '-e' => (file_exists($argv[3] ?? '') || is_link($argv[3] ?? '')) ? 0 : 1,
                        '-L' => is_link($argv[3] ?? '') ? 0 : 1,
                        default => 1,
                    };
                } elseif (($argv[0] ?? '') === 'sudo' && ($argv[1] ?? '') === 'mkdir') {
                    $exit = @mkdir($argv[3] ?? '', 0755, true) ? 0 : 1;
                } elseif (($argv[0] ?? '') === 'sudo' && ($argv[1] ?? '') === 'chmod') {
                    $exit = @chmod($argv[3] ?? '', octdec($argv[2] ?? '0700')) ? 0 : 1;
                }

                return new class ($exit) extends Process {
                    public function __construct(private int $code)
                    {
                        parent::__construct(['true']);
                    }

                    public function isSuccessful(): bool
                    {
                        return $this->code === 0;
                    }
                };
            }
        };
    }
}
