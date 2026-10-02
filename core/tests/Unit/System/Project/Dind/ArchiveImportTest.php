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
            [['sudo', 'rsync', '-a', '--delete', $extract . '/myapp/', $this->root . '/home/alice/project/']],
            $this->commandsStartingWith($system, ['sudo', 'rsync'])
        );
    }

    public function test_a_flat_archive_is_copied_as_it_is(): void
    {
        $system = $this->system("f index.php\0d assets\0");
        $this->files($system)->importProjectArchive('/fw.tar.gz');

        $extract = $this->commandsStartingWith($system, ['sudo', 'find'])[0][2];
        $this->assertSame(
            [['sudo', 'rsync', '-a', '--delete', $extract . '/', $this->root . '/home/alice/project/']],
            $this->commandsStartingWith($system, ['sudo', 'rsync'])
        );
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
