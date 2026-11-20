<?php

namespace Tests\Integration\System\Project;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Exceptions\NotFoundException;
use App\Exceptions\ProblemException;
use App\Lib\Http\OutboundUrl;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\FileManager;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/** How the file manager reads what the account's file commands answer, whatever find the host has. */
class FileManagerAccountAnswersTest extends TestCase
{
    private const HOME = '/home/shop';

    /** @var array<string, Process|list<Process>> */
    private array $answers = [];

    /** @var list<list<string>> */
    private array $commands = [];

    private string $exchange;

    private string|false $exchangeBefore = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exchange = sys_get_temp_dir() . '/pa-fm-exchange-' . bin2hex(random_bytes(4));
        mkdir($this->exchange, 0777, true);
        $this->exchangeBefore = getenv('PA_EXCHANGE_DIR');
        putenv('PA_EXCHANGE_DIR=' . $this->exchange);
    }

    protected function tearDown(): void
    {
        putenv($this->exchangeBefore === false ? 'PA_EXCHANGE_DIR' : 'PA_EXCHANGE_DIR=' . $this->exchangeBefore);
        exec('rm -rf ' . escapeshellarg($this->exchange) . ' ' . escapeshellarg($this->exchange . '-outside'));
        parent::tearDown();
    }

    public function test_a_listing_reads_each_entry_and_flags_directories_that_hold_one(): void
    {
        $this->answers['account.entryType'] = FakeProcess::ok("dir\n");
        $this->answers['account.listPage'] = FakeProcess::ok("4\0" . implode('', [
            $this->row('site', 'd', 4096, '755', '', ''),
            $this->row('empty', 'd', 4096, '700', '', ''),
            $this->row('to-site', 'l', 4, '777', 'site', 'd'),
            $this->row('to-note', 'l', 8, '777', 'note.txt', 'f'),
        ]));
        $this->answers['account.subdirectoryParents'] = FakeProcess::ok(self::HOME . "/site\0");

        $result = $this->files()->list('/', false, 3);

        $this->assertSame(4, $result['total']);
        $this->assertTrue($result['truncated']);
        $this->assertSame('/', $result['path']);
        $this->assertSame([
            ['site', 'dir', true, null, null, '/site', '0755'],
            ['empty', 'dir', false, null, null, '/empty', '0700'],
            ['to-site', 'link', null, 'site', 'dir', '/to-site', '0777'],
            ['to-note', 'link', null, 'note.txt', 'file', '/to-note', '0777'],
        ], array_map(static fn (array $e): array => [
            $e['name'], $e['type'], $e['has_subdirectories'], $e['target'], $e['target_type'], $e['path'], $e['mode'],
        ], $result['entries']));
        $this->assertSame(1001, $result['entries'][0]['uid']);
        $this->assertSame(1760140000, $result['entries'][0]['mtime']);
        $this->assertSame([self::HOME . '/', '3', '0'], array_slice(LocalRoot::values($this->asked('account.listPage')), 2));
    }

    public function test_a_link_to_nothing_lists_its_target_as_missing_and_a_file_as_a_file(): void
    {
        $this->answers['account.entryType'] = FakeProcess::ok("dir-link\n");
        $this->answers['account.listPage'] = FakeProcess::ok("2\0" . $this->row('gone', 'l', 3, '777', 'nowhere', 'N') . $this->row('a.txt', 'f', 5, '644', '', ''));

        $entries = $this->files()->list('/www')['entries'];

        $this->assertSame(['link', 'missing', '/www/gone'], [$entries[0]['type'], $entries[0]['target_type'], $entries[0]['path']]);
        $this->assertSame(['file', 5, null], [$entries[1]['type'], $entries[1]['size'], $entries[1]['target']]);
        $this->assertNull($this->asked('account.subdirectoryParents'));
    }

    public function test_an_empty_directory_lists_nothing(): void
    {
        $this->answers['account.entryType'] = FakeProcess::ok("dir\n");
        $this->answers['account.listPage'] = FakeProcess::ok('');

        $this->assertSame(
            ['entries' => [], 'path' => '/', 'truncated' => false, 'total' => 0],
            $this->files()->list('/')
        );
    }

    public function test_a_directory_the_account_cannot_read_is_not_found(): void
    {
        $this->answers['account.entryType'] = FakeProcess::ok("dir\n");
        $this->answers['account.listPage'] = FakeProcess::failed('Permission denied');

        $this->expectException(NotFoundException::class);
        $this->files()->list('/private');
    }

    public function test_a_missing_path_is_not_found_and_a_file_is_not_a_directory(): void
    {
        $this->answers['account.entryType'] = FakeProcess::ok("dangling\n");
        try {
            $this->files()->list('/gone');
            $this->fail('A dangling link was listed');
        } catch (NotFoundException $e) {
            $this->assertSame('No such file or directory: ' . self::HOME . '/gone', $e->getMessage());
        }

        $this->answers['account.entryType'] = FakeProcess::ok("file\n");
        try {
            $this->files()->list('/note.txt');
            $this->fail('A file was listed');
        } catch (ProblemException $e) {
            $this->assertStringContainsString('The path is not a directory.', json_encode($e->problems));
        }
    }

    public function test_a_folder_the_account_cannot_write_to_refuses_mkdir(): void
    {
        $this->answers['account.makeFolder'] = FakeProcess::of(6, self::HOME . '/locked');

        try {
            $this->files()->mkdir('/locked/new');
            $this->fail('mkdir into a read-only folder succeeded');
        } catch (ProblemException $e) {
            $this->assertStringContainsString(
                "/locked is not a folder the project's account can write to",
                json_encode($e->problems, JSON_UNESCAPED_SLASHES)
            );
        }
    }

    public function test_an_upload_into_a_folder_the_account_cannot_write_to_is_refused(): void
    {
        $source = $this->exchange . '/pa-upload-1';
        file_put_contents($source, 'body');
        $this->answers['account.writeCopy'] = FakeProcess::of(6);

        try {
            $this->files()->moveUploadedFile('/locked', new UploadedFile($source, 'a.txt', null, null, true));
            $this->fail('the upload was written');
        } catch (ProblemException $e) {
            $this->assertStringContainsString('/locked is not a folder', json_encode($e->problems, JSON_UNESCAPED_SLASHES));
        }
        $this->assertSame([$source, self::HOME . '/locked/a.txt'], array_slice(LocalRoot::values($this->asked('account.writeCopy')), 2));
    }

    public function test_an_upload_that_cannot_be_staged_writes_nothing(): void
    {
        $source = $this->exchange . '-outside';
        file_put_contents($source, 'body');
        $file = new class ($source, 'a.txt', null, null, true) extends UploadedFile {
            public function path(): string
            {
                return $this->getPathname() . '/vanished';
            }
        };

        try {
            $this->files()->moveUploadedFile('/', $file);
            $this->fail('a missing upload was written');
        } catch (\RuntimeException $e) {
            $this->assertSame('Cannot stage the file contents', $e->getMessage());
        }
        $this->assertNull($this->asked('account.writeCopy'));
        $this->assertSame([], glob($this->exchange . '/pa-put-*'));
    }

    public function test_move_and_copy_run_as_the_account_with_both_paths(): void
    {
        $this->files()->mv('/a.txt', '/b.txt');
        $this->files()->cp('/b.txt', '/c/../d.txt');

        $this->assertSame([self::HOME . '/a.txt', self::HOME . '/b.txt'], array_slice(LocalRoot::values($this->asked('account.moveEntry')), 2));
        $this->assertSame([self::HOME . '/b.txt', self::HOME . '/d.txt'], array_slice(LocalRoot::values($this->asked('account.copyEntry')), 2));
    }

    public function test_a_failed_move_names_the_commands_error(): void
    {
        $this->answers['account.moveEntry'] = FakeProcess::failed('mv: cannot move');

        $this->expectExceptionMessage('mv: cannot move (exit code 1)');
        $this->files()->mv('/a.txt', '/b.txt');
    }

    public function test_zipping_from_a_parent_that_is_not_a_directory_is_refused(): void
    {
        $this->answers['account.entryType'] = FakeProcess::ok("missing\n");

        $this->expectExceptionMessage('Invalid path');
        $this->files()->zip('/out.zip', '/gone/site', skipParents: true);
    }

    public function test_a_fetch_with_an_unsafe_name_touches_nothing(): void
    {
        try {
            $this->files()->fetch('https://downloads.example.test/a.zip', '/', '..');
            $this->fail('an unsafe name was fetched');
        } catch (\Exception $e) {
            $this->assertSame('Invalid filename', $e->getMessage());
        }
        $this->assertSame([], $this->commands);
    }

    public function test_a_redirect_without_a_location_ends_the_fetch(): void
    {
        $this->answers['account.entryType'] = FakeProcess::ok("dir\n");
        $this->answers['account.download'] = FakeProcess::ok('302');
        $this->answers['account.redirectLocation'] = FakeProcess::ok('');

        $this->expectExceptionMessage('Fetching https://downloads.example.test/a.zip answered HTTP 302 with no Location');
        $this->fetchingFiles()->fetch('https://downloads.example.test/a.zip', '/');
    }

    public function test_a_fetch_that_keeps_redirecting_gives_up(): void
    {
        $this->answers['account.entryType'] = FakeProcess::ok("dir\n");
        $this->answers['account.download'] = FakeProcess::ok('301');
        $this->answers['account.redirectLocation'] = FakeProcess::ok('/again');

        try {
            $this->fetchingFiles()->fetch('https://downloads.example.test/a.zip', '/');
            $this->fail('the redirects were followed forever');
        } catch (\Exception $e) {
            $this->assertSame(
                'Fetching https://downloads.example.test/a.zip followed more than ' . OutboundUrl::MAX_REDIRECTS . ' redirects',
                $e->getMessage()
            );
        }
        $this->assertNotNull($this->asked('account.removeFile'));
    }

    private function row(string $name, string $type, int $size, string $mode, string $target, string $targetType): string
    {
        return implode("\0", [$name, $type, (string) $size, '1760140000.25', $mode, '1001', '1001', $target, $targetType]) . "\0";
    }

    /** @return list<string>|null the last request for $action */
    private function asked(string $action): ?array
    {
        $found = null;
        foreach ($this->commands as $command) {
            if (LocalRoot::action($command) === $action) {
                $found = $command;
            }
        }

        return $found;
    }

    private function files(): FileManager
    {
        return new FileManager($this->project());
    }

    private function fetchingFiles(): FileManager
    {
        return new class ($this->project()) extends FileManager {
            protected function assertOutboundAllowed(string $url): void
            {
            }

            protected function outboundPin(string $url): ?string
            {
                return null;
            }
        };
    }

    private function project(): Project
    {
        $model = new User();
        $model->username = 'shop';
        $model->setDetails(['UID' => 1001, 'GID' => 1001]);

        return new Project($this->system(), $model);
    }

    private function system(): System
    {
        $answers = &$this->answers;
        $commands = &$this->commands;

        return new class ($answers, $commands) extends System {
            /**
             * @param array<string, Process> $answers
             * @param list<list<string>> $commands
             */
            public function __construct(private array &$answers, private array &$commands)
            {
            }

            public function projectHomeDirPath(string $username): string
            {
                return '/home/' . $username;
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null,
            ): Process {
                return $this->runProcess($cmd, $env, $timeout);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = $cmd;

                return $this->answers[LocalRoot::action($cmd) ?? ''] ?? FakeProcess::ok();
            }
        };
    }
}
