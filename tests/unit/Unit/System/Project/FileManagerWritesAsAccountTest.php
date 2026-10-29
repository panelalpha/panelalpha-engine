<?php

namespace Tests\Unit\System\Project;

use App\Exceptions\ProblemException;
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

    /** A link the account planted must not carry a write out of its home. */
    public function test_a_write_through_a_link_out_of_the_home_is_refused(): void
    {
        $outside = $this->homeDir . '.outside';
        mkdir($outside);
        try {
            symlink($outside . '/probe', $this->homeDir . '/filelink');
            symlink($outside, $this->homeDir . '/dirlink');
            file_put_contents($this->homeDir . '/mine.txt', 'mine');
            $files = $this->files();

            $refused = [
                'put-contents through a file link' => fn () => $files->putContents('filelink', 'x'),
                'put-contents through a directory link' => fn () => $files->putContents('dirlink/probe', 'x'),
                'mkdir' => fn () => $files->mkdir('dirlink/made', true),
                'upload' => fn () => $files->moveUploadedFile('dirlink', $this->upload()),
                'cp' => fn () => $files->cp('mine.txt', 'dirlink/copied'),
                'mv' => fn () => $files->mv('mine.txt', 'dirlink/moved'),
                'chmod' => fn () => $files->chmod('filelink', '0777'),
            ];
            foreach ($refused as $what => $call) {
                try {
                    $call();
                    $this->fail("{$what} was not refused");
                } catch (\Exception $e) {
                    $this->assertStringContainsString('resolves outside the project home', $e->getMessage(), $what);
                }
            }

            $this->assertSame(['.', '..'], scandir($outside), 'nothing was written outside the home');
            $this->assertFileExists($this->homeDir . '/mine.txt');
        } finally {
            exec('rm -rf ' . escapeshellarg($outside));
        }
    }

    public function test_links_inside_the_home_and_removing_a_link_still_work(): void
    {
        mkdir($this->homeDir . '/real');
        symlink($this->homeDir . '/real', $this->homeDir . '/inner');
        symlink('/etc/hostname', $this->homeDir . '/away');
        $files = $this->files();

        $files->putContents('inner/index.html', 'hi');
        $this->assertSame('hi', file_get_contents($this->homeDir . '/real/index.html'));

        // rm takes the link itself, wherever it points.
        $files->remove('away');
        $this->assertFalse(is_link($this->homeDir . '/away'));
        $this->assertFileExists('/etc/hostname');
    }

    /** A classic project's home belongs to root: an upload to `/` must be refused on `path`, not with a shell error. */
    public function test_an_upload_into_a_folder_the_account_cannot_write_to_is_refused_on_path(): void
    {
        $this->assertRefusedAsNotWritable('', '/', fn (FileManager $files) => $files->moveUploadedFile('/', $this->upload()));
    }

    public function test_an_upload_into_a_missing_folder_below_one_the_account_cannot_write_to_is_refused_on_path(): void
    {
        $this->assertRefusedAsNotWritable('', '/media/new', fn (FileManager $files) => $files->moveUploadedFile('media/new', $this->upload()));
    }

    public function test_a_write_into_a_folder_the_account_cannot_write_to_is_refused_on_path(): void
    {
        mkdir($this->homeDir . '/locked');
        $this->assertRefusedAsNotWritable('/locked', '/locked', fn (FileManager $files) => $files->putContents('locked/index.html', 'x'));
    }

    public function test_a_mkdir_in_a_folder_the_account_cannot_write_to_is_refused_on_path(): void
    {
        $this->assertRefusedAsNotWritable('', '/', fn (FileManager $files) => $files->mkdir('blog'));
    }

    /** The folder named is the one that exists and is refused, not the missing one above the new folder. */
    public function test_a_mkdir_with_parents_below_a_folder_the_account_cannot_write_to_is_refused_on_path(): void
    {
        $this->assertRefusedAsNotWritable('', '/', fn (FileManager $files) => $files->mkdir('media/new', true));

        mkdir($this->homeDir . '/locked');
        $this->assertRefusedAsNotWritable('/locked', '/locked', fn (FileManager $files) => $files->mkdir('locked/media/new', true));
    }

    public function test_a_mkdir_with_parents_of_a_folder_that_exists_still_succeeds_in_a_folder_it_cannot_write_to(): void
    {
        mkdir($this->homeDir . '/site');
        chmod($this->homeDir, 0555);
        try {
            $this->files()->mkdir('site', true);
        } finally {
            chmod($this->homeDir, 0755);
        }

        $this->assertDirectoryExists($this->homeDir . '/site');
    }

    public function test_a_file_the_account_can_write_is_still_replaced_in_a_folder_it_cannot_write_to(): void
    {
        file_put_contents($this->homeDir . '/mine.txt', 'old');
        chmod($this->homeDir, 0555);
        try {
            $this->files()->putContents('mine.txt', 'new');
        } finally {
            chmod($this->homeDir, 0755);
        }

        $this->assertSame('new', file_get_contents($this->homeDir . '/mine.txt'));
    }

    private function assertRefusedAsNotWritable(string $locked, string $named, callable $write): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes to a 0555 directory.');
        }
        chmod($this->homeDir . $locked, 0555);
        try {
            $write($this->files());
            $this->fail('a file was written into a folder the account cannot write to');
        } catch (ProblemException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('path', $e->problems[0]['field']);
            $this->assertSame('not_writable', $e->problems[0]['code']);
            $this->assertSame("{$named} is not a folder the project's account can write to: choose a folder inside the project.", $e->problems[0]['message']);
        } finally {
            chmod($this->homeDir . $locked, 0755);
        }
        $this->assertSame([], glob($this->homeDir . $locked . '/{photo.jpg,index.html,media,blog}', GLOB_BRACE) ?: []);
    }

    private function upload(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($tmp, 'uploaded');

        return new UploadedFile($tmp, 'photo.jpg', null, null, true);
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
