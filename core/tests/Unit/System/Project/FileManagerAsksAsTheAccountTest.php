<?php

namespace Tests\Unit\System\Project;

use App\Exceptions\NotFoundException;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\FileManager;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Whether a path exists is asked as the account, the way the command it guards
 * runs. The engine's PHP is another user: a file in a directory only the
 * account can enter was "not found" there while rm or stat would have worked.
 */
class FileManagerAsksAsTheAccountTest extends TestCase
{
    private string $homeDir;

    /** @var list<list<string>> */
    private array $commands = [];

    /** @var array<string, string> path => what the account sees there, for paths the engine cannot */
    private array $accountOnly = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->homeDir = sys_get_temp_dir() . '/pa-asks-' . bin2hex(random_bytes(4));
        mkdir($this->homeDir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->homeDir) . ' ' . escapeshellarg($this->homeDir . '.outside'));
        parent::tearDown();
    }

    public function test_a_missing_path_is_not_found_and_nothing_else_runs(): void
    {
        $files = $this->files();

        $this->assertNotFound(fn () => $files->remove('nothing-here'));
        $this->assertNotFound(fn () => $files->stat('nothing-here'));

        $this->assertSame(['confine', 'entry-type', 'confine', 'entry-type'], $this->ran());
    }

    public function test_a_link_whose_target_is_gone_is_removed_but_has_no_stat(): void
    {
        symlink($this->homeDir . '/gone', $this->homeDir . '/dangling');
        $files = $this->files();

        $this->assertNotFound(fn () => $files->stat('dangling'));
        $files->remove('dangling');

        $this->assertFalse(is_link($this->homeDir . '/dangling'));
    }

    public function test_a_directory_needs_recursive_and_a_link_to_one_does_not(): void
    {
        mkdir($this->homeDir . '/dir/sub', 0777, true);
        mkdir($this->homeDir . '/target');
        symlink($this->homeDir . '/target', $this->homeDir . '/dirlink');
        $files = $this->files();

        try {
            $files->remove('dir');
            $this->fail('A directory went without recursive');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('recursive', $e->errors());
        }
        $this->assertDirectoryExists($this->homeDir . '/dir/sub');

        $files->remove('dirlink');
        $this->assertFalse(is_link($this->homeDir . '/dirlink'));
        $this->assertDirectoryExists($this->homeDir . '/target');

        $files->remove('dir', true);
        $this->assertDirectoryDoesNotExist($this->homeDir . '/dir');
    }

    public function test_a_file_only_the_account_can_see_is_found(): void
    {
        $this->accountOnly[$this->homeDir . '/private/secret.txt'] = 'file';
        $files = $this->files();

        $this->assertFileDoesNotExist($this->homeDir . '/private/secret.txt');
        $this->assertSame('7', $files->stat('private/secret.txt')['size']);
        $files->remove('private/secret.txt');

        $this->assertSame(['confine', 'entry-type', 'stat', 'confine', 'entry-type', 'rm'], $this->ran());
        foreach ($this->commands as $argv) {
            $this->assertSame(
                ['sudo', 'setpriv', '--reuid', '1001', '--regid', '1001', '--clear-groups'],
                array_slice($argv, 0, 7),
                'not run as the account: ' . implode(' ', $argv)
            );
        }
    }

    /** Refused before anything is looked up there, so the answer says nothing about what is outside. */
    public function test_a_path_through_a_link_out_of_the_home_is_refused_whether_or_not_it_exists(): void
    {
        $outside = $this->homeDir . '.outside';
        mkdir($outside);
        file_put_contents($outside . '/there.txt', 'x');
        symlink($outside, $this->homeDir . '/out');
        $files = $this->files();

        foreach (['out/there.txt', 'out/not-there.txt'] as $path) {
            try {
                $files->remove($path);
                $this->fail("{$path} was not refused");
            } catch (\Exception $e) {
                $this->assertStringContainsString('resolves outside the project home', $e->getMessage());
            }
        }

        $this->assertSame(['confine', 'confine'], $this->ran());
        $this->assertFileExists($outside . '/there.txt');
    }

    /** A link into another account, to a system file, or dangling towards either says nothing about its target. */
    public function test_stat_and_exists_refuse_a_link_out_of_the_home(): void
    {
        $outside = $this->homeDir . '.outside';
        mkdir($outside);
        file_put_contents($outside . '/theirs.txt', 'x');
        symlink($outside, $this->homeDir . '/other-account');
        symlink('/etc/hostname', $this->homeDir . '/system-file');
        symlink($outside . '/gone', $this->homeDir . '/dangling-out');
        $files = $this->files();

        foreach (['other-account/theirs.txt', 'other-account/nothing.txt', 'system-file', 'dangling-out'] as $path) {
            foreach (['stat' => fn () => $files->stat($path), 'exists' => fn () => $files->exists($path)] as $what => $call) {
                try {
                    $call();
                    $this->fail("{$what} {$path} was not refused");
                } catch (NotFoundException $e) {
                    $this->fail("{$what} {$path} was looked up outside the home: {$e->getMessage()}");
                } catch (\Exception $e) {
                    $this->assertStringContainsString('resolves outside the project home', $e->getMessage(), "{$what} {$path}");
                }
            }
        }
        $this->assertSame(array_fill(0, 8, 'confine'), $this->ran(), 'nothing was asked past the home check');
    }

    public function test_stat_and_exists_inside_the_home_are_unchanged(): void
    {
        mkdir($this->homeDir . '/real');
        file_put_contents($this->homeDir . '/real/file.txt', 'hello');
        symlink($this->homeDir . '/real', $this->homeDir . '/inner');
        symlink($this->homeDir . '/gone', $this->homeDir . '/dangling-in');
        $files = $this->files();

        $this->assertTrue($files->exists('real/file.txt'));
        $this->assertTrue($files->exists('inner/file.txt'));
        $this->assertSame('5', $files->stat('inner/file.txt')['size']);
        $this->assertFalse($files->exists('dangling-in'));
        $this->assertNotFound(fn () => $files->stat('dangling-in'));
        $this->assertFalse($files->exists('nothing-here'));
    }

    public function test_a_destination_directory_is_asked_after_the_home_check(): void
    {
        mkdir($this->homeDir . '/source');
        $files = $this->files();

        try {
            $files->moveDirectoryContents('source', 'missing');
            $this->fail('A missing destination was accepted');
        } catch (\Exception $e) {
            $this->assertSame('Destination directory does not exist', $e->getMessage());
        }
        $this->assertSame(['confine', 'entry-type'], $this->ran());
    }

    private function assertNotFound(\Closure $call): void
    {
        try {
            $call();
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertStringContainsString('No such file or directory', $e->getMessage());
        }
    }

    /** @return list<string> what ran, in order */
    private function ran(): array
    {
        return array_map(function (array $argv): string {
            $argv = array_slice($argv, 7);

            return match (true) {
                in_array(FileManager::CONFINE_SCRIPT, $argv, true) => 'confine',
                in_array(FileManager::ENTRY_TYPE_SCRIPT, $argv, true) => 'entry-type',
                default => $argv[0],
            };
        }, $this->commands);
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
        $accountOnly = &$this->accountOnly;

        return new class ($this->homeDir, $commands, $accountOnly) extends System {
            /**
             * @param list<list<string>> $commands
             * @param array<string, string> $accountOnly
             */
            public function __construct(private string $homeRoot, private array &$commands, private array &$accountOnly)
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

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                throw new \RuntimeException('root command: ' . (is_array($cmd) ? implode(' ', $cmd) : $cmd));
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = (array) $cmd;
                $argv = array_slice((array) $cmd, 7);
                $path = (string) end($argv);
                if (isset($this->accountOnly[rtrim($path, '/')])) {
                    // What the account answers for a path the engine cannot enter.
                    $answer = match (true) {
                        in_array(FileManager::ENTRY_TYPE_SCRIPT, $argv, true) => $this->accountOnly[rtrim($path, '/')] . "\n",
                        $argv[0] === 'stat' => "{$path} 7 1001 1001 1 2 3 4",
                        default => '',
                    };
                    $argv = ['printf', '%s', $answer];
                }
                $process = new Process($argv);
                $process->run();

                return $process;
            }
        };
    }
}
