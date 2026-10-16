<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Filesystem;
use App\System\Privileged\AccountFiles;
use App\System\ProcessRunner;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;
use Tests\Unit\System\Project\LocalHostSystem;

class FilesystemTemplateFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-fs-template-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir . '/vhost.blade.php', 'server_name {{ $domain }};');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_the_template_is_rendered_into_the_target(): void
    {
        $filesystem = new Filesystem(new LocalHostSystem($this->dir, $this->dir));

        $filesystem->makeFileFromTemplate($this->dir . '/out/vhost.conf', $this->dir . '/vhost.blade.php', ['domain' => 'example.com']);

        $this->assertSame('server_name example.com;', file_get_contents($this->dir . '/out/vhost.conf'));
    }

    public function test_a_failed_copy_removes_the_rendered_temp_file(): void
    {
        $staged = null;
        $filesystem = new class (new LocalHostSystem($this->dir, $this->dir), $staged) extends Filesystem {
            public function __construct(ProcessRunner $system, private mixed &$staged)
            {
                parent::__construct($system);
            }

            public function copyFile(string $source, string $target, ?string $chown = null, ?string $chmod = null): void
            {
                $this->staged = $source;

                throw new \RuntimeException('sudo cp failed');
            }
        };

        try {
            $filesystem->makeFileFromTemplate($this->dir . '/vhost.conf', $this->dir . '/vhost.blade.php', ['domain' => 'example.com']);
            $this->fail('copyFile should have thrown');
        } catch (\RuntimeException) {
        }

        $this->assertNotNull($staged, 'the template was never rendered');
        $this->assertFileDoesNotExist($staged, 'the rendered temp file was left behind');
    }

    public function test_a_template_is_written_by_the_account_and_does_not_follow_a_planted_link(): void
    {
        mkdir($this->dir.'/tpl/assets', 0755, true);
        file_put_contents($this->dir.'/tpl/index.html', 'welcome');
        file_put_contents($this->dir.'/tpl/assets/site.css', 'body{}');
        file_put_contents($this->dir.'/tpl/info.php.blade.php', 'site {{ $domain }}');
        mkdir($this->dir.'/home/alice/a.test/public_html', 0755, true);
        file_put_contents($this->dir.'/host-file', 'host');
        symlink($this->dir.'/host-file', $this->dir.'/home/alice/a.test/public_html/index.html');
        $system = $this->executingSystem();

        (new Filesystem($system))->makeDirFromTemplateAsAccount(
            new AccountFiles(1001, 1001),
            $this->dir.'/home/alice/a.test/public_html',
            $this->dir.'/tpl',
            ['domain' => 'a.test'],
        );

        $root = $this->dir.'/home/alice/a.test/public_html';
        $this->assertSame('host', file_get_contents($this->dir.'/host-file'));
        $this->assertFalse(is_link($root.'/index.html'));
        $this->assertSame('welcome', file_get_contents($root.'/index.html'));
        $this->assertSame('body{}', file_get_contents($root.'/assets/site.css'));
        $this->assertSame('site a.test', file_get_contents($root.'/info.php'));
        $this->assertNotSame([], $system->commands);
        foreach ($system->commands as $argv) {
            $request = array_slice($argv, (int) array_search(LocalRoot::CLIENT, $argv, true));
            $this->assertStringStartsWith('account.', (string) LocalRoot::action($request), implode(' ', $argv));
            $this->assertSame(['1001', '1001'], array_slice(LocalRoot::values($request), 0, 2));
        }
    }

    /** Runs each command as this test process, through pa-root-local. */
    private function executingSystem(): System
    {
        return new class extends System
        {
            /** @var list<list<string>> */
            public array $commands = [];

            public function __construct() {}

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = array_values((array) $cmd);
                $this->commands[] = $argv;

                $local = array_map(static fn (string $word): string => $word === LocalRoot::CLIENT ? LocalRoot::client() : $word, $argv);

                return (new Process($local))->mustRun()->getOutput();
            }
        };
    }
}
