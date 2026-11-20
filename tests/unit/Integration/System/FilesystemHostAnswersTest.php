<?php

namespace Tests\Integration\System;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System;
use App\System\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/** What the filesystem helpers make of each answer the root broker gives them. */
class FilesystemHostAnswersTest extends TestCase
{
    private const HOMES = '/home';

    /** @var array<string, Process|string|\Throwable> */
    private array $answers = [];

    /** @var list<list<string>> */
    private array $commands = [];

    /** @var array<string, string> what each copy request was handed, by target */
    private array $copied = [];

    private string $root;

    private string|false $exchangeBefore = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-fs-answers-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/exchange', 0777, true);
        $this->exchangeBefore = getenv('PA_EXCHANGE_DIR');
        putenv('PA_EXCHANGE_DIR=' . $this->root . '/exchange');
        $this->forgetHomeFilesystem();
    }

    protected function tearDown(): void
    {
        putenv($this->exchangeBefore === false ? 'PA_EXCHANGE_DIR' : 'PA_EXCHANGE_DIR=' . $this->exchangeBefore);
        $this->forgetHomeFilesystem();
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_rename_into_the_home_that_fails_names_the_brokers_error(): void
    {
        $this->answers['files.deviceNumbers'] = FakeProcess::ok("2049\n2049\n");
        $this->answers['account.renameInHome'] = FakeProcess::of(1, '', "mv: cannot move: Read-only file system\n");

        try {
            $this->filesystem()->writeFileReplacingPath(self::HOMES . '/alice/project/.env', "A=1\n", '1000:1000', '600');
            $this->fail('the write was reported as done');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression(
                "~^Cannot move '/home/\\.panelalpha-write-stage/[0-9a-f]{16}' to '/home/alice/project/\\.env': mv: cannot move: Read-only file system$~",
                $e->getMessage()
            );
        }
        $this->assertSame('files.removeIfExists', LocalRoot::action(end($this->commands)));
    }

    public function test_a_directory_template_renders_its_blade_files_and_copies_the_rest(): void
    {
        $template = $this->root . '/template';
        mkdir($template . '/conf', 0777, true);
        file_put_contents($template . '/conf/site.conf.blade.php', 'server_name {{ $domain }};');
        file_put_contents($template . '/static.txt', 'as is');

        $this->filesystem()->makeDirFromTemplate('/opt/target', $template, ['domain' => 'shop.example.test'], null, '640');

        $this->assertSame('server_name shop.example.test;', $this->copied['/opt/target/conf/site.conf']);
        $this->assertSame('as is', $this->copied['/opt/target/static.txt']);
        $this->assertContains(['chmod', '--', '640', '/opt/target/conf/site.conf'], $this->plainCommands());
    }

    public function test_a_home_path_the_broker_cannot_resolve_reads_as_nothing(): void
    {
        $this->answers['files.existingRealPaths'] = FakeProcess::of(1);

        $this->assertNull($this->filesystem()->cat(self::HOMES . '/alice/missing.txt'));
        $this->assertNull($this->filesystem()->cat(self::HOMES . '/../etc/passwd'));
        $this->assertSame(1, count(array_filter($this->commands, static fn (array $c): bool => LocalRoot::action($c) === 'files.existingRealPaths')));
        $this->assertNotContains('account.readInHome', array_map(static fn (array $c): ?string => LocalRoot::action($c), $this->commands));
    }

    public function test_modification_time_and_tail(): void
    {
        $this->answers['files.modificationTime'] = FakeProcess::ok('1760140000');
        $this->answers['files.lastLines'] = FakeProcess::ok("last line\n");

        $this->assertSame(1760140000, $this->filesystem()->mtime('/var/log/app.log'));
        $this->assertSame("last line\n", $this->filesystem()->tail('/var/log/app.log', 1));

        $this->answers['files.modificationTime'] = FakeProcess::failed('stat: cannot stat');
        $this->answers['files.lastLines'] = FakeProcess::failed('tail: cannot open');

        $this->assertNull($this->filesystem()->mtime('/var/log/gone.log'));
        $this->assertNull($this->filesystem()->tail('/var/log/gone.log', 1));
    }

    public function test_the_home_mount_point_is_read_from_df(): void
    {
        $this->answers['files.diskFree'] = "Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/sda2 100 40 60 40% /home\n";

        $this->assertSame('/home', $this->filesystem()->getHomeFilesystemMountPoint());
    }

    public function test_free_space_that_no_staging_filesystem_reports_is_an_error(): void
    {
        $this->answers['probes.diskFree'] = "Filesystem 1K-blocks Used Available Use% Mounted on\n";

        $this->expectExceptionMessage('Could not determine free space on staging filesystem');
        $this->filesystem()->assertFreeSpace(1);
    }

    public function test_a_df_line_without_a_number_of_free_blocks_does_not_count(): void
    {
        $this->answers['probes.diskFree'] = "Filesystem 1K-blocks Used Available Use% Mounted on\n/dev/sda2 100 40 lots 40% /var/tmp\n";

        $this->expectExceptionMessage('Could not determine free space on staging filesystem');
        $this->filesystem()->assertFreeSpace(1);
    }

    private function filesystem(): Filesystem
    {
        return new Filesystem($this->system());
    }

    /** @return list<list<string>> */
    private function plainCommands(): array
    {
        return array_map(static fn (array $cmd): array => LocalRoot::plain($cmd), $this->commands);
    }

    private function forgetHomeFilesystem(): void
    {
        (new \ReflectionProperty(Filesystem::class, 'homeFilesystemInfo'))->setValue(null, null);
    }

    private function system(): System
    {
        $answers = &$this->answers;
        $commands = &$this->commands;
        $copied = &$this->copied;

        return new class ($answers, $commands, $copied) extends System {
            /**
             * @param array<string, Process|string> $answers
             * @param list<list<string>> $commands
             * @param array<string, string> $copied
             */
            public function __construct(private array &$answers, private array &$commands, private array &$copied)
            {
            }

            public function homesDirPath(): string
            {
                return '/home';
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
                $answer = $this->answers[LocalRoot::action($cmd) ?? ''] ?? FakeProcess::ok();

                return $answer instanceof Process ? $answer : FakeProcess::ok($answer);
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->commands[] = $cmd;
                if (LocalRoot::action($cmd) === 'files.copy') {
                    [$from, $to] = LocalRoot::values($cmd);
                    $this->copied[$to] = (string) file_get_contents($from);
                }
                $answer = $this->answers[LocalRoot::action($cmd) ?? ''] ?? '';

                return $answer instanceof Process ? $answer->getOutput() : $answer;
            }
        };
    }
}
