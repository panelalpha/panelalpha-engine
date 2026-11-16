<?php

namespace Tests\Unit\System\Project;

use App\Exceptions\NotFoundException;
use App\Exceptions\ProblemException;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\FileManager;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;

class FileManagerListTest extends TestCase
{
    private string $homeDir;

    /** @var list<list<string>> */
    private array $commands = [];

    /** @var list<string> directories whose listing is answered with a permission error */
    private array $denied = [];

    /** @var list<int> bytes each command printed */
    private array $outputBytes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->homeDir = sys_get_temp_dir() . '/pa-list-' . bin2hex(random_bytes(4));
        mkdir($this->homeDir, 0777, true);
        $this->requireGnuFind();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->homeDir) . ' ' . escapeshellarg($this->homeDir . '.outside'));
        parent::tearDown();
    }

    public function test_it_lists_entries_with_type_size_mtime_mode_and_ownership(): void
    {
        file_put_contents($this->homeDir . '/note.txt', 'hello');
        touch($this->homeDir . '/note.txt', 1760140000);
        chmod($this->homeDir . '/note.txt', 0644);
        mkdir($this->homeDir . '/site');

        $entries = $this->byName($this->files()->list('/')['entries']);

        $this->assertSame(['site', 'note.txt'], array_keys($entries));
        $this->assertSame('file', $entries['note.txt']['type']);
        $this->assertSame('dir', $entries['site']['type']);
        $this->assertSame(5, $entries['note.txt']['size']);
        $this->assertSame(1760140000, $entries['note.txt']['mtime']);
        $this->assertSame('0644', $entries['note.txt']['mode']);
        $this->assertSame(fileowner($this->homeDir . '/note.txt'), $entries['note.txt']['uid']);
        $this->assertSame(filegroup($this->homeDir . '/note.txt'), $entries['note.txt']['gid']);
        $this->assertSame('/note.txt', $entries['note.txt']['path']);
        $this->assertNull($entries['note.txt']['target']);
        $this->assertNull($entries['note.txt']['target_type']);
        $this->assertNull($entries['note.txt']['has_subdirectories']);
    }

    public function test_directories_come_first_then_names_in_byte_order(): void
    {
        file_put_contents($this->homeDir . '/b.txt', 'b');
        file_put_contents($this->homeDir . '/a.txt', 'a');
        mkdir($this->homeDir . '/zdir');
        mkdir($this->homeDir . '/adir');

        $names = array_column($this->files()->list('/')['entries'], 'name');

        $this->assertSame(['adir', 'zdir', 'a.txt', 'b.txt'], $names);
    }

    public function test_names_with_spaces_newlines_and_utf8_survive(): void
    {
        $names = ['with space.txt', "line\nbreak.txt", 'zażółć.txt'];
        foreach ($names as $name) {
            file_put_contents($this->homeDir . '/' . $name, $name);
        }

        $entries = $this->byName($this->files()->list('/')['entries']);

        foreach ($names as $name) {
            $this->assertArrayHasKey($name, $entries);
            $this->assertSame(strlen($name), $entries[$name]['size']);
            $this->assertSame('/' . $name, $entries[$name]['path']);
        }
    }

    public function test_a_link_reports_its_target_and_target_type(): void
    {
        file_put_contents($this->homeDir . '/target.txt', 'x');
        mkdir($this->homeDir . '/subdir');
        symlink('target.txt', $this->homeDir . '/file-link');
        symlink('subdir', $this->homeDir . '/dir-link');
        symlink('nowhere', $this->homeDir . '/dangling');

        $entries = $this->byName($this->files()->list('/')['entries']);

        $this->assertSame('link', $entries['file-link']['type']);
        $this->assertSame('target.txt', $entries['file-link']['target']);
        $this->assertSame('file', $entries['file-link']['target_type']);
        $this->assertNull($entries['file-link']['has_subdirectories']);

        $this->assertSame('link', $entries['dir-link']['type']);
        $this->assertSame('subdir', $entries['dir-link']['target']);
        $this->assertSame('dir', $entries['dir-link']['target_type']);

        $this->assertSame('link', $entries['dangling']['type']);
        $this->assertSame('nowhere', $entries['dangling']['target']);
        $this->assertSame('missing', $entries['dangling']['target_type']);
    }

    public function test_a_link_out_of_the_home_is_listed_but_not_followed(): void
    {
        $outside = $this->homeDir . '.outside';
        mkdir($outside);
        file_put_contents($outside . '/theirs.txt', 'x');
        symlink($outside, $this->homeDir . '/escape');

        $entries = $this->byName($this->files()->list('/')['entries']);

        $this->assertSame('link', $entries['escape']['type']);
        $this->assertSame('dir', $entries['escape']['target_type']);

        try {
            $this->files()->list('escape');
            $this->fail('Listing through a link out of the home was allowed');
        } catch (ProblemException $e) {
            $this->assertSame('outside_home', $e->problems[0]['code']);
        }
    }

    public function test_a_file_is_not_a_directory_and_a_missing_path_is_not_found(): void
    {
        file_put_contents($this->homeDir . '/note.txt', 'x');

        try {
            $this->files()->list('note.txt');
            $this->fail('A file was listed');
        } catch (ProblemException $e) {
            $this->assertSame('not_a_directory', $e->problems[0]['code']);
        }

        $this->expectException(NotFoundException::class);
        $this->files()->list('nothing-here');
    }

    public function test_show_hidden_false_omits_dot_entries(): void
    {
        file_put_contents($this->homeDir . '/.hidden', 'x');
        file_put_contents($this->homeDir . '/visible.txt', 'x');

        $names = array_column($this->files()->list('/', false)['entries'], 'name');

        $this->assertSame(['visible.txt'], $names);
    }

    public function test_limit_truncates_and_flags_it(): void
    {
        foreach (['a.txt', 'b.txt', 'c.txt'] as $name) {
            file_put_contents($this->homeDir . '/' . $name, 'x');
        }

        $result = $this->files()->list('/', true, 2);

        $this->assertSame(['a.txt', 'b.txt'], array_column($result['entries'], 'name'));
        $this->assertTrue($result['truncated']);
        $this->assertSame(3, $result['total']);
        $this->assertSame('/', $result['path']);
    }

    public function test_only_the_page_is_read_from_a_large_directory(): void
    {
        $prefix = str_repeat('n', 200);
        for ($i = 0; $i < 300; $i++) {
            touch(sprintf('%s/%s-%03d', $this->homeDir, $prefix, $i));
        }

        $result = $this->files()->list('/', true, 2);

        $this->assertSame([$prefix . '-000', $prefix . '-001'], array_column($result['entries'], 'name'));
        $this->assertSame(300, $result['total']);
        $this->assertTrue($result['truncated']);
        $this->assertLessThan(2048, max($this->outputBytes), 'the whole directory was read to return two entries');
    }

    public function test_the_page_is_cut_after_sorting_names_by_byte_order(): void
    {
        foreach (['a.txt', 'a', 'a-b', 'B'] as $name) {
            file_put_contents($this->homeDir . '/' . $name, 'x');
        }
        mkdir($this->homeDir . '/z');

        $result = $this->files()->list('/', true, 3);

        $this->assertSame(['z', 'B', 'a'], array_column($result['entries'], 'name'));
        $this->assertSame(5, $result['total']);
    }

    public function test_hidden_entries_are_left_out_of_the_count_too(): void
    {
        file_put_contents($this->homeDir . '/.hidden', 'x');
        mkdir($this->homeDir . '/.config');
        file_put_contents($this->homeDir . '/visible.txt', 'x');

        $result = $this->files()->list('/', false, 1);

        $this->assertSame(['visible.txt'], array_column($result['entries'], 'name'));
        $this->assertSame(1, $result['total']);
        $this->assertFalse($result['truncated']);
    }

    public function test_an_empty_directory_lists_nothing(): void
    {
        $result = $this->files()->list('/');

        $this->assertSame([], $result['entries']);
        $this->assertSame(0, $result['total']);
        $this->assertFalse($result['truncated']);
    }

    public function test_has_subdirectories_is_true_only_for_a_directory_that_holds_one(): void
    {
        mkdir($this->homeDir . '/parent/child', 0777, true);
        mkdir($this->homeDir . '/empty');
        mkdir($this->homeDir . '/childless');
        file_put_contents($this->homeDir . '/childless/file.txt', 'x');

        $entries = $this->byName($this->files()->list('/')['entries']);

        $this->assertTrue($entries['parent']['has_subdirectories']);
        $this->assertFalse($entries['empty']['has_subdirectories']);
        $this->assertFalse($entries['childless']['has_subdirectories']);
    }

    public function test_the_listing_runs_as_the_account(): void
    {
        file_put_contents($this->homeDir . '/note.txt', 'x');

        $this->files()->list('/');

        $this->assertNotSame([], $this->commands);
        foreach ($this->commands as $argv) {
            $this->assertStringStartsWith('account.', (string) LocalRoot::action($argv), 'not run as the account: ' . implode(' ', $argv));
            $this->assertSame(['1001', '1001'], array_slice(LocalRoot::values($argv), 0, 2), 'not run as the account: ' . implode(' ', $argv));
        }
    }

    public function test_an_unreadable_directory_is_listed_but_not_entered(): void
    {
        mkdir($this->homeDir . '/docker', 0700);
        $this->denied[] = $this->homeDir . '/docker';

        $entries = $this->byName($this->files()->list('/')['entries']);

        $this->assertSame('dir', $entries['docker']['type']);
        $this->assertSame('0700', $entries['docker']['mode']);

        try {
            $this->files()->list('docker');
            $this->fail('An unreadable directory was entered');
        } catch (NotFoundException) {
        }
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array<string, array<string, mixed>>
     */
    private function byName(array $entries): array
    {
        $keyed = [];
        foreach ($entries as $entry) {
            $keyed[$entry['name']] = $entry;
        }

        return $keyed;
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
        $denied = &$this->denied;
        $outputBytes = &$this->outputBytes;

        return new class ($this->homeDir, $commands, $denied, $outputBytes) extends System {
            /**
             * @param list<list<string>> $commands
             * @param list<string> $denied
             * @param list<int> $outputBytes
             */
            public function __construct(
                private string $homeRoot,
                private array &$commands,
                private array &$denied,
                private array &$outputBytes,
            ) {
            }

            public function projectHomeDirPath(string $username): string
            {
                return $this->homeRoot;
            }

            public function projectDirPath(string $username): string
            {
                return $this->homeRoot . '/engine';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = (array) $cmd;
                $argv = LocalRoot::plain((array) $cmd);
                if ($this->isDeniedListing($argv)) {
                    $argv = ['sh', '-c', 'exit 1'];
                }
                $process = new Process($argv);
                $process->run();
                $this->outputBytes[] = strlen($process->getOutput());

                return $process;
            }

            /** The page listing of a denied directory: `sh -c <script> sh <dir>/ <limit>`. */
            private function isDeniedListing(array $argv): bool
            {
                return ($argv[0] ?? '') === 'sh'
                    && ctype_digit((string) ($argv[5] ?? ''))
                    && in_array(rtrim((string) ($argv[4] ?? ''), '/'), $this->denied, true);
            }
        };
    }
}
