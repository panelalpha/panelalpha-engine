<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Filesystem;
use App\System\Project;
use App\System\Project\FileManager;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The file API's writes run as the account, never as root: root follows a
 * symlink the account planted (`~/x -> /etc/cron.d/x`, or into another
 * account's home) and writes and chowns the file wherever it points.
 */
class FileManagerWritesAsAccountTest extends TestCase
{
    private string $homeDir;

    /** @var list<list<string>> */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->homeDir = sys_get_temp_dir() . '/pa-writes-' . bin2hex(random_bytes(4));
        mkdir($this->homeDir, 0777, true);
        $this->commands = [];
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->homeDir));
        parent::tearDown();
    }

    public function test_put_contents_writes_as_the_account(): void
    {
        $this->files()->putContents('site/index.html', 'hello');

        $this->assertSame('hello', file_get_contents($this->homeDir . '/site/index.html'));
        $this->assertAllAsAccount();
    }

    public function test_put_contents_replaces_an_existing_file(): void
    {
        mkdir($this->homeDir . '/site');
        file_put_contents($this->homeDir . '/site/index.html', 'old and longer');

        $this->files()->putContents('site/index.html', 'new');

        $this->assertSame('new', file_get_contents($this->homeDir . '/site/index.html'));
        $this->assertSame('0644', substr(sprintf('%o', fileperms($this->homeDir . '/site/index.html')), -4));
    }

    public function test_mkdir_runs_as_the_account(): void
    {
        $files = $this->files();
        $files->mkdir('a');
        $files->mkdir('b/c/d', true);

        $this->assertDirectoryExists($this->homeDir . '/a');
        $this->assertDirectoryExists($this->homeDir . '/b/c/d');
        $this->assertAllAsAccount();
    }

    public function test_an_upload_is_written_as_the_account(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($tmp, 'uploaded');
        chmod($tmp, 0600);
        $upload = new UploadedFile($tmp, 'photo.jpg', null, null, true);

        $this->files()->moveUploadedFile('media/new', $upload);

        $this->assertSame('uploaded', file_get_contents($this->homeDir . '/media/new/photo.jpg'));
        $this->assertAllAsAccount();
        @unlink($tmp);
    }

    private function assertAllAsAccount(): void
    {
        $this->assertNotSame([], $this->commands);
        foreach ($this->commands as $argv) {
            $this->assertSame(
                ['sudo', 'setpriv', '--reuid', '1001', '--regid', '1001', '--clear-groups'],
                array_slice($argv, 0, 7),
                'ran as root: ' . implode(' ', $argv)
            );
        }
    }

    private function files(): FileManager
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(['template' => 'dind', 'deploy_strategy' => 'php', 'UID' => 1001, 'GID' => 1001]);

        return new FileManager(new Project($this->system(), $model), $this->homeDir . '/.unzip-stage');
    }

    private function system(): System
    {
        $commands = &$this->commands;

        return new class ($this->homeDir, $commands) extends System {
            /** @param list<list<string>> $commands */
            public function __construct(private string $homeRoot, private array &$commands)
            {
            }

            public function projectHomeDirPath(string $username): string
            {
                return $this->homeRoot;
            }

            public function projectDirPath(string $username): string
            {
                return $this->homeRoot . '/engine';
            }

            // Every root write the engine has goes through one of these.
            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                throw new \RuntimeException('root command: ' . (is_array($cmd) ? implode(' ', $cmd) : $cmd));
            }

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        throw new \RuntimeException('root write: ' . $path);
                    }

                    public function makeDirWithParents(string $target, ?string $chown = null): void
                    {
                        throw new \RuntimeException('root mkdir: ' . $target);
                    }
                };
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = is_array($cmd) ? $cmd : [$cmd];
                $argv = is_array($cmd) && ($cmd[1] ?? '') === 'setpriv' ? array_slice($cmd, 7) : (array) $cmd;
                if (($argv[0] ?? '') === 'sudo') {
                    throw new \RuntimeException('root process: ' . implode(' ', $argv));
                }
                $process = new Process($argv);
                $process->run();

                return $process;
            }
        };
    }
}
