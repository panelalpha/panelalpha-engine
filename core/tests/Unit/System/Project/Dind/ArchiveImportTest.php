<?php

namespace Tests\Unit\System\Project\Dind;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\Source\Files;
use PHPUnit\Framework\TestCase;

/**
 * The archive is extracted under ~/.panelalpha, which is the account's alone
 * (0700): core's PHP cannot list it, so the single top-level directory has to
 * be found by a command run as root, or `myapp/` lands in ~/project as is.
 */
class ArchiveImportTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-archive-import-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/home/alice/project', 0777, true);
        mkdir($this->root . '/stage', 0777, true);
        file_put_contents($this->root . '/home/alice/fw.tar.gz', gzencode('not inspected here'));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_single_top_level_directory_is_unwrapped_though_php_cannot_read_the_extract(): void
    {
        $system = $this->system("d myapp\0");
        $this->files($system)->importProjectArchive('/fw.tar.gz');

        $find = $this->commandsStartingWith($system, ['sudo', 'find']);
        $this->assertCount(1, $find);
        $extract = $find[0][2];
        $this->assertStringStartsWith($this->root . '/home/alice/.panelalpha/archive-extract-', $extract);
        // Nothing was extracted for real: only the root listing knows what is there.
        $this->assertDirectoryDoesNotExist($extract);

        $this->assertSame(
            [['sudo', 'rsync', '-a', '--delete', '--checksum', '--no-times', $extract . '/myapp/', $this->root . '/home/alice/project/']],
            $this->commandsStartingWith($system, ['sudo', 'rsync'])
        );
    }

    public function test_a_flat_archive_is_copied_as_it_is(): void
    {
        $system = $this->system("f index.php\0d assets\0");
        $this->files($system)->importProjectArchive('/fw.tar.gz');

        $extract = $this->commandsStartingWith($system, ['sudo', 'find'])[0][2];
        $this->assertSame(
            [['sudo', 'rsync', '-a', '--delete', '--checksum', '--no-times', $extract . '/', $this->root . '/home/alice/project/']],
            $this->commandsStartingWith($system, ['sudo', 'rsync'])
        );
    }

    /** Zip keeps a link's target as the member's contents: unzip -p reads it before anything is extracted. */
    public function test_a_zip_link_inside_the_archive_is_read_and_allowed(): void
    {
        $system = $this->zipSystem('AGENTS.md');
        $this->files($system)->importProjectArchive('/site.zip');

        $this->assertSame(
            [['sudo', 'unzip', '-p', $this->stagedZip($system), 'app/CLAUDE.md']],
            $this->commandsStartingWith($system, ['sudo', 'unzip', '-p'])
        );
        $this->assertCount(1, $this->commandsStartingWith($system, ['sudo', 'rsync']));
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

        $this->assertSame([], $this->commandsStartingWith($system, ['sudo', 'setpriv']));
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
            'sudo unzip -Z1 ' => "app/AGENTS.md\napp/CLAUDE.md\n",
            'sudo unzip -Z ' => "-rw-r--r--  3.0 unx    5 tx stor 26-Oct-03 10:00 app/AGENTS.md\n"
                . "lrwxrwxrwx  3.0 unx    9 bx stor 26-Oct-03 10:00 app/CLAUDE.md\n",
            'sudo unzip -p ' => $target,
        ];

        return $system;
    }

    private function stagedZip(System $system): string
    {
        return $this->commandsStartingWith($system, ['sudo', 'cp', '--no-dereference'])[0][4];
    }

    /** Reproducible builds stamp every file alike: a same-length edit must still land. */
    public function test_a_changed_file_with_the_same_size_and_mtime_is_replaced(): void
    {
        if (trim((string) shell_exec('command -v rsync')) === '') {
            $this->markTestSkipped('rsync is not installed');
        }
        $system = $this->system("f VERSION\0");
        $this->files($system)->importProjectArchive('/fw.tar.gz');
        $rsync = $this->commandsStartingWith($system, ['sudo', 'rsync'])[0];

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

    private function files(System $system): Files
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(['template' => 'dind', 'UID' => 1000, 'GID' => 1000, 'deploy_strategy' => 'static']);
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
                if (is_array($cmd) && array_slice($cmd, 0, 3) === ['sudo', 'cp', '--no-dereference']) {
                    @mkdir(dirname($cmd[4]), 0777, true);
                    copy($cmd[3], $cmd[4]);
                }
                if (is_array($cmd) && array_slice($cmd, 0, 2) === ['sudo', 'find']) {
                    return $this->listing;
                }
                foreach ($this->answers as $prefix => $output) {
                    if (is_array($cmd) && str_starts_with(implode(' ', $cmd), $prefix)) {
                        return $output;
                    }
                }

                return '';
            }
        };
    }

    /** @return list<list<string>> */
    private function commandsStartingWith(System $system, array $prefix): array
    {
        return array_values(array_filter(
            $system->commands,
            static fn ($cmd): bool => is_array($cmd) && array_slice($cmd, 0, count($prefix)) === $prefix
        ));
    }
}
