<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\StagedRename;
use Tests\TestCase;
use Tests\Unit\System\Project\LocalHostSystem;

/**
 * The tenant owns its project directory and can swap a directory in it for a
 * symlink at any moment, including after the engine checked the path and
 * before it reads or renames. Here the swap lands at the worst moment: right
 * before the command that reads or renames.
 */
class FilesystemAccountHomeRaceTest extends TestCase
{
    private string $root;

    private string $homes;

    private string $alice;

    private string $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pa-fs-race-'.bin2hex(random_bytes(4));
        $this->homes = $this->root.'/home';
        $this->alice = $this->homes.'/alice/project';
        $this->bob = $this->homes.'/bob/project';
        mkdir($this->alice.'/server', 0777, true);
        mkdir($this->bob, 0777, true);
        file_put_contents($this->alice.'/server/.env', "A=alice\n");
        file_put_contents($this->bob.'/.env', "DB_PASSWORD=bob-secret\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_read_does_not_follow_a_directory_swapped_after_the_check(): void
    {
        try {
            $contents = $this->filesystem()->fileGetContents($this->alice.'/server/.env');
            $this->fail('Read through the swapped directory: '.$contents);
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('bob-secret', $e->getMessage());
        }
    }

    public function test_cat_does_not_follow_a_directory_swapped_after_the_check(): void
    {
        $this->assertNull($this->filesystem()->cat($this->alice.'/server/.env'));
    }

    public function test_a_write_is_not_renamed_through_a_directory_swapped_after_the_check(): void
    {
        try {
            $this->filesystem()->writeFileReplacingPath($this->alice.'/server/.env', "A=tenant\n", '1000:1000', '600');
            $this->fail('The write went ahead.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('a directory above it is a symlink', $e->getMessage());
        }
        $this->assertBobUntouched();
    }

    public function test_a_new_directory_is_not_renamed_through_a_directory_swapped_after_the_check(): void
    {
        try {
            $this->filesystem()->makeDirWithParents($this->alice.'/server/config', '1000:1000');
            $this->fail('The directory was created.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('a directory above it is a symlink', $e->getMessage());
        }
        $this->assertBobUntouched();
    }

    public function test_without_a_swap_the_same_read_and_write_work(): void
    {
        $filesystem = $this->filesystem(swap: false);

        $filesystem->writeFileReplacingPath($this->alice.'/server/.env', "A=new\n", '1000:1000', '600');

        $this->assertSame("A=new\n", $filesystem->fileGetContents($this->alice.'/server/.env'));
        $this->assertSame('600', sprintf('%o', fileperms($this->alice.'/server/.env') & 0777));
    }

    /** The directory holding the homes is the host's: a link there is followed, as before. */
    public function test_the_homes_directory_itself_may_be_a_link(): void
    {
        symlink($this->homes, $this->root.'/homes-link');
        $alice = $this->root.'/homes-link/alice/project';
        $filesystem = $this->filesystem(swap: false, homes: $this->root.'/homes-link');

        $filesystem->writeFileReplacingPath($alice.'/server/.env', "A=linked\n", '1000:1000', '600');

        $this->assertSame("A=linked\n", $filesystem->fileGetContents($alice.'/server/.env'));
    }

    private function assertBobUntouched(): void
    {
        $this->assertSame("DB_PASSWORD=bob-secret\n", file_get_contents($this->bob.'/.env'));
        $this->assertSame(['.', '..', '.env'], scandir($this->bob));
        $this->assertSame(['.', '..'], scandir($this->homes.'/'.Filesystem::WRITE_STAGE_NAME));
    }

    /** `~/project/server` becomes a link to bob's project, once, just before the read or the rename. */
    private function swapServerForALinkToBob(): void
    {
        rename($this->alice.'/server', $this->alice.'/server-moved');
        symlink($this->bob, $this->alice.'/server');
    }

    private function filesystem(bool $swap = true, ?string $homes = null): Filesystem
    {
        $homes ??= $this->homes;
        $local = new LocalHostSystem($this->root, $homes);
        $beforeLastStep = $swap ? fn () => $this->swapServerForALinkToBob() : null;

        return new Filesystem(new class($local, $homes, $beforeLastStep) extends System
        {
            public function __construct(
                private LocalHostSystem $local,
                private string $homes,
                private ?\Closure $beforeLastStep,
            ) {}

            public function homesDirPath(): string
            {
                return $this->homes;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->swapBefore((array) $cmd);

                return $this->local->exec($cmd, $env, $timeout);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->swapBefore((array) $cmd);

                return $this->local->runProcess($cmd, $env, $timeout);
            }

            /** @param list<string> $argv */
            private function swapBefore(array $argv): void
            {
                $renamesIntoTheHome = str_starts_with(StagedRename::of($argv)[1] ?? '', $this->homes.'/alice/');
                $reads = in_array($argv[1] ?? '', ['dd', 'cat'], true) || ($argv[4] ?? '') === 'read';
                if ($this->beforeLastStep !== null && ($renamesIntoTheHome || $reads)) {
                    ($this->beforeLastStep)();
                    $this->beforeLastStep = null;
                }
            }
        });
    }
}
