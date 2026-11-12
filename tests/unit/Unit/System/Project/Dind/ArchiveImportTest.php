<?php

namespace Tests\Unit\System\Project\Dind;

use App\Exceptions\MissingLinuxUserException;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Privileged;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\Source\Files;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\BrokerScripts;
use Tests\Support\LocalRoot;

/**
 * The archive is extracted under ~/.panelalpha, which is the account's alone
 * (0700): core's PHP cannot list it, so the single top-level directory has to
 * be found by a command run as the account, or `myapp/` lands in ~/project as is.
 */
class ArchiveImportTest extends TestCase
{
    private const AS_ACCOUNT = ['account', '1000', '1000'];

    private const AS_ROOT = ['root'];

    public const EXTRACTIONS = ['account.extractZip', 'account.extractTar'];

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-archive-import-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/home/alice/project', 0777, true);
        mkdir($this->root . '/stage', 0777, true);
        putenv('PA_ROOT_LOCAL_ROOTS=' . json_encode(['homes' => $this->root . '/home']));
        file_put_contents($this->root . '/home/alice/fw.tar.gz', gzencode('not inspected here'));
    }

    protected function tearDown(): void
    {
        putenv('PA_ROOT_LOCAL_ROOTS');
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_single_top_level_directory_is_unwrapped_though_php_cannot_read_the_extract(): void
    {
        $system = $this->system("d myapp\0");
        $this->files($system)->importProjectArchive('/fw.tar.gz');

        $find = $this->extractFind($system);
        $extract = $find[4];
        $this->assertStringStartsWith($this->root . '/home/alice/.panelalpha/archive-extract-', $extract);
        // Nothing was extracted for real: only the root listing knows what is there.
        $this->assertDirectoryDoesNotExist($extract);

        $this->assertSame(
            [[...self::AS_ROOT, 'rsync', '-a', '--delete', '--checksum', '--no-times', '--', $extract . '/myapp/', $this->root . '/home/alice/project/']],
            $this->commandsStartingWith($system, [...self::AS_ROOT, 'rsync'])
        );
    }

    public function test_the_extract_directory_is_made_by_the_account(): void
    {
        $system = $this->system("f index.php\0");
        $this->files($system)->importProjectArchive('/fw.tar.gz');

        $extract = $this->extractFind($system)[4];
        $home = $this->root.'/home/alice';
        $this->assertContains(Privileged::scripts()->makeHomeEntry($home.'/.panelalpha', '0700', '1000:1000'), $system->commands);
        $this->assertContains([...self::AS_ACCOUNT, 'mkdir', '-p', '--', $extract], $this->describedCommands($system));
        $this->assertContains(Privileged::scripts()->makeHomeEntry($home.'/project', '0755', '1000:1000'), $system->commands);
        $this->assertSame([], array_filter(
            $this->commandsStartingWith($system, [...self::AS_ROOT, 'mkdir']),
            static fn (array $cmd): bool => str_starts_with((string) end($cmd), $home)
        ));
        $this->assertSame(
            [[...self::AS_ROOT, 'chown', '-R', '--', '1000:1000', $home.'/project']],
            $this->commandsStartingWith($system, [...self::AS_ROOT, 'chown'])
        );
    }

    /** The archive is looked up as the account: the engine's PHP cannot enter ~/.panelalpha either. */
    public function test_an_archive_only_the_account_can_see_is_imported(): void
    {
        $archive = $this->root . '/home/alice/.panelalpha/archives/fw.tar.gz';
        $system = $this->system("f index.php\0");
        $system->accountOnly = [$archive];

        $this->files($system)->importProjectArchive('/.panelalpha/archives/fw.tar.gz');

        $this->assertSame(
            [[...self::AS_ACCOUNT, 'sh', '-c', BrokerScripts::resolvedFile(), 'sh', $archive]],
            $this->commandsStartingWith($system, [...self::AS_ACCOUNT, 'sh'])
        );
        $copy = $this->stagingCopy($system);
        $this->assertSame($archive, end($copy));
        $this->assertSame(
            [...self::AS_ACCOUNT, 'sh', '-c'],
            array_slice($copy, 4, 5),
            'the archive is read as the account and written by the engine'
        );
        $this->assertCount(1, $this->commandsStartingWith($system, [...self::AS_ROOT, 'rsync']));
    }

    public function test_the_staged_copy_is_made_by_the_engine_and_left_read_only(): void
    {
        $system = $this->system("f index.php\0");
        $this->files($system)->importProjectArchive('/fw.tar.gz');

        $staged = $this->stagingCopy($system)[3];
        clearstatcache();
        $this->assertSame('0444', substr(sprintf('%o', fileperms($staged)), -4));
        $this->assertSame('0755', substr(sprintf('%o', fileperms(dirname($staged))), -4));
        $this->assertSame(gzencode('not inspected here'), file_get_contents($staged));
        $rootOnStage = array_filter(
            $this->commandsStartingWith($system, self::AS_ROOT),
            fn (array $cmd): bool => in_array($cmd[1], ['mkdir', 'chmod', 'cp', 'chown'], true)
                && str_contains(implode(' ', $cmd), $this->root.'/stage')
        );
        $this->assertSame([], array_values($rootOnStage));
    }

    public function test_a_flat_archive_is_copied_as_it_is(): void
    {
        $system = $this->system("f index.php\0d assets\0");
        $this->files($system)->importProjectArchive('/fw.tar.gz');

        $extract = $this->extractFind($system)[4];
        $this->assertSame(
            [[...self::AS_ROOT, 'rsync', '-a', '--delete', '--checksum', '--no-times', '--', $extract . '/', $this->root . '/home/alice/project/']],
            $this->commandsStartingWith($system, [...self::AS_ROOT, 'rsync'])
        );
    }

    /**
     * A killed import skips the `finally`: an archive-sized copy under the
     * stage root and an extract under the home are swept before the next one.
     * Both are found as root and removed; the current import's own entries are
     * younger than the threshold and are not listed.
     */
    public function test_stale_stage_and_extract_directories_are_swept_before_an_import(): void
    {
        $staleStage = $this->root . '/stage/deadbeef01';
        $staleExtract = $this->root . '/home/alice/.panelalpha/archive-extract-deadbeef02';
        $system = $this->system("f index.php\0");
        $system->answers = [
            'root find ' . $this->root . '/stage ' => $staleStage . "\n",
            'root find ' . $this->root . '/home/alice/.panelalpha ' => $staleExtract . "\n",
        ];

        $this->files($system)->importProjectArchive('/fw.tar.gz');

        $sweeps = $this->commandsStartingWith($system, [...self::AS_ROOT, 'find']);
        $this->assertCount(2, $sweeps);
        $this->assertSame(
            [...self::AS_ROOT, 'find', $this->root . '/stage', '-mindepth', '1', '-maxdepth', '1', '-type', 'd', '-name', '*', '-mmin', '+1440', '-print'],
            $sweeps[0]
        );
        $this->assertSame(
            [...self::AS_ROOT, 'find', $this->root . '/home/alice/.panelalpha', '-mindepth', '1', '-maxdepth', '1', '-type', 'd', '-name', 'archive-extract-*', '-mmin', '+1440', '-print'],
            $sweeps[1]
        );
        $removals = array_values(array_filter(
            $this->commandsStartingWith($system, [...self::AS_ROOT, 'rm', '-rf']),
            static fn ($cmd): bool => count($cmd) === 5
        ));
        $this->assertSame(
            [[...self::AS_ROOT, 'rm', '-rf', '--', $staleStage], [...self::AS_ROOT, 'rm', '-rf', '--', $staleExtract]],
            $removals
        );
    }

    /** Zip keeps a link's target as the member's contents: unzip -p reads it before anything is extracted. */
    public function test_a_zip_link_inside_the_archive_is_read_and_allowed(): void
    {
        $system = $this->zipSystem('AGENTS.md');
        $this->files($system)->importProjectArchive('/site.zip');

        $this->assertSame(
            [['env', 'LC_ALL=C.UTF-8', 'unzip', '-p', $this->stagedZip($system), 'app/CLAUDE.md']],
            $this->commandsStartingWith($system, ['env', 'LC_ALL=C.UTF-8', 'unzip', '-p'])
        );
        $this->assertCount(1, $this->commandsStartingWith($system, [...self::AS_ROOT, 'rsync']));
    }

    public function test_a_zip_link_out_of_the_archive_is_refused_before_extraction(): void
    {
        $system = $this->zipSystem('/etc');
        try {
            $this->files($system)->importProjectArchive('/site.zip');
            $this->fail('the link was not refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('symbolic link', $e->getMessage());
        }

        $this->assertSame([], $this->extractions($system));
    }

    public function test_a_zip_that_unpacked_with_a_warning_is_written_into_the_project(): void
    {
        $system = $this->zipSystem('AGENTS.md');
        $system->extraction = [0, "warning:  /proc/self/fd/7 appears to use backslashes as path separators\n"];

        $this->files($system)->importProjectArchive('/site.zip');

        $this->assertCount(1, $this->extractions($system));
        $this->assertCount(1, $this->commandsStartingWith($system, [...self::AS_ROOT, 'rsync']));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function failedExtractions(): iterable
    {
        yield 'zip' => [
            '/site.zip',
            "  End-of-central-directory signature not found.\n"
                ."unzip:  cannot find zipfile directory in one of /proc/self/fd/7 or\n        /proc/self/fd/7.zip, and cannot find /proc/self/fd/7.ZIP, period.\n",
            'Could not unpack site.zip: End-of-central-directory signature not found. '
                .'unzip: cannot find zipfile directory in one of site.zip or site.zip.zip, and cannot find site.zip.ZIP, period.',
        ];
        yield 'tarball' => [
            '/fw.tar.gz',
            "\ngzip: stdin: not in gzip format\ntar: Child returned status 1\ntar: Error is not recoverable: exiting now\n",
            'Could not unpack fw.tar.gz: gzip: stdin: not in gzip format tar: Child returned status 1 tar: Error is not recoverable: exiting now',
        ];
    }

    #[DataProvider('failedExtractions')]
    public function test_a_failed_extraction_names_the_archive_and_writes_nothing_into_the_project(string $archive, string $said, string $message): void
    {
        $system = str_ends_with($archive, '.zip') ? $this->zipSystem('AGENTS.md') : $this->system("f index.php\0");
        $system->extraction = [str_ends_with($archive, '.zip') ? 9 : 2, $said];

        try {
            $this->files($system)->importProjectArchive($archive);
            $this->fail('the failed extraction went on');
        } catch (\RuntimeException $e) {
            $this->assertSame($message, $e->getMessage());
        }

        $this->assertSame([], $this->commandsStartingWith($system, [...self::AS_ROOT, 'rsync']));
    }

    /** The broker's own failure exits 1 too, so only the extraction script decides that unzip's 1 went fine. */
    public function test_an_extraction_the_broker_could_not_run_fails(): void
    {
        $system = $this->zipSystem('AGENTS.md');
        $system->extraction = [1, "pa-root: Permission denied\n"];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not unpack site.zip: pa-root: Permission denied');

        $this->files($system)->importProjectArchive('/site.zip');
    }

    /** @return list<list<string>> */
    private function extractions(System $system): array
    {
        return array_values(array_filter(
            $system->commands,
            static fn ($cmd): bool => is_array($cmd) && in_array(LocalRoot::action($cmd), self::EXTRACTIONS, true)
        ));
    }

    private function zipSystem(string $target): System
    {
        $zip = new \ZipArchive();
        $zip->open($this->root . '/home/alice/site.zip', \ZipArchive::CREATE);
        $zip->addFromString('app/AGENTS.md', 'hello');
        $zip->addFromString('app/CLAUDE.md', $target);
        $zip->setExternalAttributesName('app/CLAUDE.md', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();

        $system = $this->system("d app\0");
        $system->answers = [
            'env LC_ALL=C.UTF-8 unzip -Z1 ' => "app/AGENTS.md\napp/CLAUDE.md\n",
            'env LC_ALL=C.UTF-8 unzip -Z ' => "-rw-r--r--  3.0 unx    5 tx stor 26-Oct-03 10:00 app/AGENTS.md\n"
                . "lrwxrwxrwx  3.0 unx    9 bx stor 26-Oct-03 10:00 app/CLAUDE.md\n",
            'env LC_ALL=C.UTF-8 unzip -p ' => $target,
        ];

        return $system;
    }

    private function stagedZip(System $system): string
    {
        return $this->stagingCopy($system)[3];
    }

    /** @return list<string> */
    private function stagingCopy(System $system): array
    {
        return $this->commandsStartingWith($system, ['sh', '-c'])[0];
    }

    /** The account's listing of the current import's extract -- not one of the sweep finds. */
    private function extractFind(System $system): array
    {
        return $this->commandsStartingWith($system, [...self::AS_ACCOUNT, 'find'])[0];
    }

    /** Reproducible builds stamp every file alike: a same-length edit must still land. */
    public function test_a_changed_file_with_the_same_size_and_mtime_is_replaced(): void
    {
        if (trim((string) shell_exec('command -v rsync')) === '') {
            $this->markTestSkipped('rsync is not installed');
        }
        $system = $this->system("f VERSION\0");
        $this->files($system)->importProjectArchive('/fw.tar.gz');
        $rsync = $this->commandsStartingWith($system, [...self::AS_ROOT, 'rsync'])[0];

        $extract = rtrim($rsync[count($rsync) - 2], '/');
        $project = rtrim($rsync[count($rsync) - 1], '/');
        mkdir($extract, 0777, true);
        file_put_contents($extract . '/VERSION', 'a4');
        file_put_contents($project . '/VERSION', 'a1');
        file_put_contents($extract . '/KEEP', 'same');
        file_put_contents($project . '/KEEP', 'same');
        foreach (['VERSION', 'KEEP'] as $f) {
            touch($extract . '/' . $f, 1_790_000_000);
            touch($project . '/' . $f, 1_790_000_000);
        }

        exec(implode(' ', array_map('escapeshellarg', array_slice($rsync, 1))), $out, $code);

        $this->assertSame(0, $code);
        $this->assertSame('a4', file_get_contents($project . '/VERSION'));
        clearstatcache();
        // A build context compared by stat must see the change, and only that one.
        $this->assertNotSame(1_790_000_000, filemtime($project . '/VERSION'));
        $this->assertSame(1_790_000_000, filemtime($project . '/KEEP'));
    }

    /** The archive is looked up as the account first, so a missing gid is refused there. */
    public function test_an_account_without_a_recorded_gid_is_refused_before_anything_is_staged(): void
    {
        $system = $this->system("f index.php\0");

        try {
            $this->files($system, ['UID' => 1000])->importProjectArchive('/fw.tar.gz');
            $this->fail('an account without a gid cannot extract');
        } catch (MissingLinuxUserException $e) {
            $this->assertSame("Project 'alice' has no Linux user recorded; rebuild it to create one.", $e->getMessage());
        }

        $this->assertSame([], $this->commandsStartingWith($system, ['sh', '-c']));
        $this->assertSame([], $this->commandsStartingWith($system, [...self::AS_ROOT, 'rm', '-rf']));
    }

    /** @param array<string, int> $ids */
    private function files(System $system, array $ids = ['UID' => 1000, 'GID' => 1000]): Files
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(['template' => 'dind', ...$ids, 'deploy_strategy' => 'static']);
        $runtime = (new ProjectAggregate($system, $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return new Files($runtime, $this->root . '/stage');
    }

    /**
     * Records every command and runs none, except the copy into the stage
     * (its size is counted by PHP) and the root listing of the extract.
     */
    private function system(string $listing): System
    {
        return new class ($this->root, $listing) extends System {
            /** @var list<list<string>|string> */
            public array $commands = [];

            /** @var array<string, string> output by command prefix */
            public array $answers = [];

            /** @var list<string> files the account sees and this process cannot */
            public array $accountOnly = [];

            /** @var array{int, string} how the extraction ends: its exit code and what it wrote on stderr */
            public array $extraction = [0, ''];

            public function __construct(private string $root, private string $listing)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root;
            }

            public function homesDirPath(): string
            {
                return $this->root . '/home';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->commands[] = $cmd;
                if (is_array($cmd) && array_slice($cmd, 0, 2) === ['sh', '-c']) {
                    $from = (string) end($cmd);
                    is_file($from) ? copy($from, $cmd[3]) : file_put_contents($cmd[3], gzencode('read as the account'));
                }
                foreach ($this->answers as $prefix => $output) {
                    if (is_array($cmd) && str_starts_with(implode(' ', ArchiveImportTest::described($cmd)), $prefix)) {
                        return $output;
                    }
                }
                if (is_array($cmd) && LocalRoot::action($cmd) === 'files.staleDirectoriesStartingWith') {
                    // A sweep find lists stale entries; by default there are none.
                    return '';
                }
                if (is_array($cmd) && LocalRoot::action($cmd) === 'account.listEntryTypes') {
                    return $this->listing;
                }

                return '';
            }

            /** An account command, run as ourselves by pa-root-local; the extraction only ends as $extraction says. */
            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = $cmd;
                $argv = LocalRoot::argv((array) $cmd);
                if (in_array(end($argv), $this->accountOnly, true)) {
                    $argv = ['printf', '%s\n', end($argv)];
                }
                if (in_array(LocalRoot::action((array) $cmd), ArchiveImportTest::EXTRACTIONS, true)) {
                    $argv = ['sh', '-c', 'printf %s "$1" >&2; exit "$0"', (string) $this->extraction[0], $this->extraction[1]];
                }
                $process = new Process($argv);
                $process->run();

                return $process;
            }
        };
    }

    /** @return list<list<string>> */
    private function commandsStartingWith(System $system, array $prefix): array
    {
        return array_values(array_filter(
            $this->describedCommands($system),
            static fn ($cmd): bool => is_array($cmd) && array_slice($cmd, 0, count($prefix)) === $prefix
        ));
    }

    /** @return list<list<string>|string> */
    private function describedCommands(System $system): array
    {
        return array_map(static fn ($cmd) => is_array($cmd) ? self::described($cmd) : $cmd, $system->commands);
    }

    /**
     * A file request as the account's ids or `root`, then the command the broker runs.
     *
     * @param  list<string>  $cmd
     * @return list<string>
     */
    public static function described(array $cmd): array
    {
        $at = array_search(LocalRoot::CLIENT, $cmd, true);
        if ($at === false || str_starts_with((string) LocalRoot::action(array_slice($cmd, $at)), 'scripts.')) {
            return $cmd;
        }
        $request = array_slice($cmd, $at);
        $who = str_starts_with((string) LocalRoot::action($request), 'account.')
            ? ['account', ...array_slice(LocalRoot::values($request), 0, 2)]
            : self::AS_ROOT;

        return [...array_slice($cmd, 0, $at), ...$who, ...LocalRoot::plain($request)];
    }
}
