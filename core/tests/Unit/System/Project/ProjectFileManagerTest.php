<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Filesystem;
use App\System\Project;
use App\System\Project\FileManager;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProjectFileManagerTest extends TestCase
{
    private string $homeDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->homeDir = sys_get_temp_dir() . '/pa-project-files-' . bin2hex(random_bytes(4));
        mkdir($this->homeDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->homeDir);
        parent::tearDown();
    }

    public function test_project_files_returns_collaborator_rooted_at_project_home(): void
    {
        $system = $this->systemWithDirectFilesystem();
        $project = new Project($system, $this->userModel('alice'));

        $files = $project->fileManager();

        $this->assertInstanceOf(FileManager::class, $files);
        $this->assertSame($this->homeDir, $files->homeDirPath());
    }

    public function test_resolve_builds_absolute_path_under_home(): void
    {
        $files = $this->fileManager();

        $this->assertSame($this->homeDir . '/project', $files->resolvePath('project'));
        $this->assertSame($this->homeDir . '/public_html/', $files->resolvePath('public_html/'));
    }

    public function test_resolve_rejects_path_traversal_and_control_characters(): void
    {
        $files = $this->fileManager();

        $rejected = 0;
        foreach (['../etc/passwd', "foo\0bar", "foo\x01bar", "foo\x0bbar", "foo\x7fbar"] as $path) {
            try {
                $files->resolvePath($path);
                $this->fail('Expected ValidationException');
            } catch (ValidationException) {
                $rejected++;
            }
        }
        $this->assertSame(5, $rejected);
    }

    public function test_aggregate_resolve_path_matches_files_collaborator(): void
    {
        $system = $this->systemWithDirectFilesystem();
        $project = new Project($system, $this->userModel('alice'));

        $this->assertSame($project->fileManager()->resolvePath('project'), $project->resolvePath('project'));
    }

    public function test_aggregate_resolve_path_replaces_legacy_var_www(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('A drive-letter home is not a slash-absolute path, which is what resolvePath confines.');
        }
        $model = $this->getMockBuilder(ModelsUser::class)
            ->onlyMethods(['getHomeDir'])
            ->getMock();
        $model->method('getHomeDir')->willReturn($this->homeDir);
        $model->username = 'alice';

        $system = $this->systemWithDirectFilesystem();
        $project = new Project($system, $model);

        $this->assertSame(
            $this->homeDir . '/project',
            $project->resolvePath('/var/www/project'),
        );
    }

    public function test_put_contents_mkdir_and_storage_round_trip(): void
    {
        $files = $this->fileManager();

        $files->mkdir('nested/dir', true);
        $files->putContents('nested/dir/hello.txt', 'panelalpha');

        $this->assertFileExists($this->homeDir . '/nested/dir/hello.txt');
        $this->assertSame('panelalpha', file_get_contents($this->homeDir . '/nested/dir/hello.txt'));

        $files->storage()->put('via-storage.txt', 'storage');
        $this->assertSame('storage', $files->storage()->get('via-storage.txt'));
        $this->assertTrue($files->storage()->exists('nested/dir/hello.txt'));
        $this->assertContains('nested', $files->storage()->directories('/'));
        $this->assertContains('via-storage.txt', $files->storage()->files('/'));
    }

    public function test_exists_stat_move_copy_and_remove_use_the_project_home(): void
    {
        $files = $this->fileManager();
        file_put_contents($this->homeDir . '/hello.txt', 'panelalpha');

        $this->assertTrue($files->exists('hello.txt'));
        $this->assertFalse($files->exists('missing.txt'));
        $this->assertSame(
            (string) filesize($this->homeDir . '/hello.txt'),
            $files->stat('hello.txt')['size']
        );

        $files->cp('hello.txt', 'copy.txt');
        $this->assertSame('panelalpha', file_get_contents($this->homeDir . '/hello.txt'));
        $this->assertSame('panelalpha', file_get_contents($this->homeDir . '/copy.txt'));

        $files->mv('copy.txt', 'moved.txt');
        $this->assertFileDoesNotExist($this->homeDir . '/copy.txt');
        $this->assertSame('panelalpha', file_get_contents($this->homeDir . '/moved.txt'));

        mkdir($this->homeDir . '/tree');
        file_put_contents($this->homeDir . '/tree/inner.txt', 'in');
        $files->remove('tree', true);
        $this->assertDirectoryDoesNotExist($this->homeDir . '/tree');
        $files->remove('moved.txt');
        $this->assertFileDoesNotExist($this->homeDir . '/moved.txt');
    }

    public function test_zip_keeps_the_child_name_and_honours_level_date_and_empty(): void
    {
        $this->requireCommand('zip');
        $files = $this->fileManager();
        mkdir($this->homeDir . '/site');
        file_put_contents($this->homeDir . '/site/old.txt', 'old');
        file_put_contents($this->homeDir . '/site/new.txt', str_repeat('a', 4096));
        touch($this->homeDir . '/site/old.txt', time() - 10 * 86400);
        touch($this->homeDir . '/site/new.txt', time());

        $files->zip('recent.zip', 'site', true, null, date('Y-m-d', time() - 86400));
        $recent = array_map('basename', $this->zipNames($this->homeDir . '/recent.zip'));
        $this->assertContains('new.txt', $recent);
        $this->assertNotContains('old.txt', $recent);

        file_put_contents($this->homeDir . '/site/zeros.bin', str_repeat("\0", 20000));
        $files->zip('plain.zip', 'site/zeros.bin', false, 0);
        $files->zip('tight.zip', 'site/zeros.bin', false, 9);
        $this->assertGreaterThan(
            filesize($this->homeDir . '/tight.zip'),
            filesize($this->homeDir . '/plain.zip')
        );

        mkdir($this->homeDir . '/empty');
        try {
            $files->zip('empty.zip', 'empty', true);
            $this->fail('An empty tree is a zip failure unless ignore_empty is set');
        } catch (\Exception) {
        }
        $files->zip('empty-ok.zip', 'empty', true, null, null, true);
    }

    public function test_unzip_restores_zip_and_tar_gz(): void
    {
        $this->requireCommand('zip');
        $this->requireCommand('unzip');
        $this->requireCommand('tar');
        $files = $this->fileManager();
        mkdir($this->homeDir . '/src');
        file_put_contents($this->homeDir . '/src/note.txt', 'restored');
        $files->zip('bundle.zip', 'src', true);

        mkdir($this->homeDir . '/out');
        $files->unzip('bundle.zip', 'out');
        $this->assertSame('restored', file_get_contents($this->homeDir . '/out/src/note.txt'));

        $tar = $this->homeDir . '/bundle.tar';
        $archive = new \PharData($tar);
        $archive->addFile($this->homeDir . '/src/note.txt', 'note.txt');
        $archive->compress(\Phar::GZ);
        unset($archive);

        mkdir($this->homeDir . '/tar-out');
        $files->unzip('bundle.tar.gz', 'tar-out');
        $this->assertSame('restored', file_get_contents($this->homeDir . '/tar-out/note.txt'));
    }

    public function test_move_directory_contents_moves_children_only(): void
    {
        $files = $this->fileManager();
        mkdir($this->homeDir . '/source');
        mkdir($this->homeDir . '/dest');
        file_put_contents($this->homeDir . '/source/a.txt', 'new');
        file_put_contents($this->homeDir . '/source/b.txt', 'b');
        file_put_contents($this->homeDir . '/dest/a.txt', 'old');

        $files->moveDirectoryContents('source', 'dest', false);
        $this->assertSame('old', file_get_contents($this->homeDir . '/dest/a.txt'));
        $this->assertSame('new', file_get_contents($this->homeDir . '/source/a.txt'));
        $this->assertSame('b', file_get_contents($this->homeDir . '/dest/b.txt'));
        $this->assertDirectoryExists($this->homeDir . '/source');

        $files->moveDirectoryContents('source', 'dest');
        $this->assertSame('new', file_get_contents($this->homeDir . '/dest/a.txt'));
        $this->assertFileDoesNotExist($this->homeDir . '/source/a.txt');
        $this->assertDirectoryExists($this->homeDir . '/source');
    }

    public function test_move_directory_contents_refuses_a_missing_destination(): void
    {
        $files = $this->fileManager();
        mkdir($this->homeDir . '/source');
        file_put_contents($this->homeDir . '/source/a.txt', 'a');

        $this->expectException(\Exception::class);
        $files->moveDirectoryContents('source', 'missing');
    }

    public function test_fetch_stores_an_http_url_and_rejects_other_schemes(): void
    {
        $files = $this->fileManager();
        $web = $this->homeDir . '/web';
        mkdir($web);
        mkdir($this->homeDir . '/inbox');
        file_put_contents($web . '/plugin.zip', 'archive-bytes');
        [$server, $port] = $this->serve($web);

        try {
            $files->fetch('http://127.0.0.1:' . $port . '/plugin.zip', 'inbox');
            $this->assertSame('archive-bytes', file_get_contents($this->homeDir . '/inbox/plugin.zip'));

            $files->fetch('http://127.0.0.1:' . $port . '/plugin.zip', 'inbox', 'renamed.bin');
            $this->assertSame('archive-bytes', file_get_contents($this->homeDir . '/inbox/renamed.bin'));
        } finally {
            $server->stop();
        }

        try {
            $files->fetch('file:///etc/passwd', 'inbox', 'stolen.txt');
            $this->fail('A non-http URL must be rejected');
        } catch (\Exception) {
        }
        $this->assertFileDoesNotExist($this->homeDir . '/inbox/stolen.txt');

        try {
            $files->fetch('http://127.0.0.1/', 'inbox');
            $this->fail('A URL with no file name must be rejected');
        } catch (\Exception $e) {
            $this->assertStringContainsString('filename', $e->getMessage());
        }
    }

    public function test_chmod_sets_an_octal_mode_and_rejects_a_bad_one(): void
    {
        $files = $this->fileManager();
        file_put_contents($this->homeDir . '/script.sh', "#!/bin/sh\n");
        chmod($this->homeDir . '/script.sh', 0644);

        $files->chmod('script.sh', '755');
        $mode = fileperms($this->homeDir . '/script.sh') & 0777;
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0755, $mode);
        }

        try {
            $files->chmod('script.sh', '999');
            $this->fail('A non-octal mode must be rejected');
        } catch (\Exception) {
        }
        $this->assertSame($mode, fileperms($this->homeDir . '/script.sh') & 0777);

        $this->expectException(ValidationException::class);
        $files->chmod('../outside.sh', '755');
    }

    public function test_disk_usage_counts_megabytes_of_the_project_home(): void
    {
        $files = $this->fileManager();
        file_put_contents($this->homeDir . '/blob.bin', str_repeat('x', 2 * 1024 * 1024));

        $this->assertGreaterThanOrEqual(1, $files->diskUsage('/'));
    }

    private function userModel(string $username): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = $username;
        $model->setDetails(['template' => 'dind', 'deploy_strategy' => 'php']);

        return $model;
    }

    private function fileManager(): FileManager
    {
        return new FileManager(new Project($this->systemWithDirectFilesystem(), $this->userModel('alice')));
    }

    private function systemWithDirectFilesystem(): System
    {
        return new class ($this->homeDir) extends System {
            public function __construct(private string $homeRoot)
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

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function filePutContents(
                        string $path,
                        string $contents,
                        ?string $chown = null,
                        ?string $chmod = null,
                    ): void {
                        $dir = dirname($path);
                        if (!is_dir($dir)) {
                            mkdir($dir, 0777, true);
                        }
                        file_put_contents($path, $contents);
                    }

                    public function makeDirWithParents(string $target, ?string $chown = null): void
                    {
                        if (!is_dir($target)) {
                            mkdir($target, 0777, true);
                        }
                    }
                };
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                if (!is_array($cmd) || in_array('docker', $cmd, true)) {
                    throw new \RuntimeException('Project file operations must not enter the project service container');
                }

                $argv = $cmd;
                if (($argv[0] ?? '') === 'sudo' && ($argv[1] ?? '') === 'setpriv') {
                    $argv = array_slice($argv, 7);
                }
                $cwd = null;
                if (($argv[0] ?? '') === 'env' && is_string($argv[1] ?? null) && str_starts_with($argv[1], '--chdir=')) {
                    $cwd = substr($argv[1], strlen('--chdir='));
                    $argv = array_slice($argv, 2);
                }

                $process = new Process($argv, $cwd);
                $process->run();

                return $process;
            }
        };
    }

    private function requireCommand(string $name): void
    {
        if ((new \Symfony\Component\Process\ExecutableFinder())->find($name) === null) {
            $this->markTestSkipped($name . ' is not installed');
        }
    }

    /**
     * @return list<string>
     */
    private function zipNames(string $path): array
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name)) {
                $names[] = $name;
            }
        }
        $zip->close();

        return $names;
    }

    /**
     * @return array{0: Process, 1: int}
     */
    private function serve(string $docroot): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr((string) $address, (int) strrpos((string) $address, ':') + 1);

        $process = new Process([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot]);
        $process->setTimeout(null);
        $process->start();

        $deadline = microtime(true) + 3;
        while (microtime(true) < $deadline) {
            $client = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if (is_resource($client)) {
                fclose($client);

                return [$process, $port];
            }
            usleep(50000);
        }

        $process->stop();
        $this->fail('Local server did not start: ' . $process->getErrorOutput());
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
