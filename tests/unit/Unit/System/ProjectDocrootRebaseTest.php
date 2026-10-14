<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Projects;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProjectDocrootRebaseTest extends TestCase
{
    public function test_a_default_docroot_follows_the_new_domain(): void
    {
        $this->assertSame(
            '/bob/public_html',
            Projects::rebasedDocumentRoot('/alice/public_html', 'alice', 'bob')
        );
    }

    public function test_a_custom_docroot_keeps_the_subpath(): void
    {
        $this->assertSame(
            '/bob/public_html/web',
            Projects::rebasedDocumentRoot('/alice/public_html/web', 'alice', 'bob')
        );
    }

    public function test_a_docroot_outside_the_source_domain_is_kept(): void
    {
        $this->assertSame(
            '/elsewhere/site',
            Projects::rebasedDocumentRoot('/elsewhere/site', 'alice', 'bob')
        );
    }

    public function test_move_account_directory_moves_a_real_directory(): void
    {
        $root = $this->tempRoot();
        mkdir($root . '/alice/public_html', 0777, true);

        (new Projects($this->localSystem()))->moveAccountDirectory($root . '/alice', $root . '/bob');

        $this->assertDirectoryExists($root . '/bob/public_html');
        $this->assertDirectoryDoesNotExist($root . '/alice');
    }

    public function test_move_account_directory_refuses_an_existing_destination(): void
    {
        $root = $this->tempRoot();
        mkdir($root . '/alice', 0777, true);
        mkdir($root . '/bob', 0777, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refusing to move over existing');

        (new Projects($this->localSystem()))->moveAccountDirectory($root . '/alice', $root . '/bob');
    }

    public function test_move_account_directory_refuses_a_destination_symlink(): void
    {
        $root = $this->tempRoot();
        mkdir($root . '/alice', 0777, true);
        mkdir($root . '/outside', 0777, true);
        symlink($root . '/outside', $root . '/bob');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refusing to move over existing');

        (new Projects($this->localSystem()))->moveAccountDirectory($root . '/alice', $root . '/bob');
    }

    public function test_move_account_directory_refuses_a_source_symlink(): void
    {
        $root = $this->tempRoot();
        mkdir($root . '/outside', 0777, true);
        symlink($root . '/outside', $root . '/alice');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not a directory');

        (new Projects($this->localSystem()))->moveAccountDirectory($root . '/alice', $root . '/bob');
    }

    private function tempRoot(): string
    {
        $root = sys_get_temp_dir() . '/pa-docroot-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);

        return $root;
    }

    private function localSystem(): System
    {
        return new class extends System {
            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? array_values($cmd) : (preg_split('/\s+/', trim($cmd)) ?: []);
                $exit = 0;

                if (($argv[0] ?? '') === 'sudo' && ($argv[1] ?? '') === 'test') {
                    $exit = match ($argv[2] ?? '') {
                        '-d' => is_dir($argv[3] ?? '') ? 0 : 1,
                        '-e' => (file_exists($argv[3] ?? '') || is_link($argv[3] ?? '')) ? 0 : 1,
                        '-L' => is_link($argv[3] ?? '') ? 0 : 1,
                        default => 1,
                    };
                } elseif (($argv[0] ?? '') === 'sudo' && ($argv[1] ?? '') === 'mv') {
                    $exit = @rename($argv[3] ?? '', $argv[4] ?? '') ? 0 : 1;
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
