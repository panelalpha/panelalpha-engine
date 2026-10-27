<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\System\Project\LocalHostSystem;

/**
 * A write into an account's home renames over the last component of the path
 * and follows every directory above it. A symlinked `~/project`, a symlinked
 * directory above a nested file, or a `..` out of the home must not carry the
 * write, rename or new directory into another account's tree.
 */
class FilesystemAccountHomeParentDirTest extends TestCase
{
    private string $root;

    private string $homes;

    private string $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pa-fs-parent-'.bin2hex(random_bytes(4));
        $this->homes = $this->root.'/home';
        $this->other = $this->homes.'/bob/project';
        mkdir($this->homes.'/alice', 0777, true);
        mkdir($this->other, 0777, true);
        file_put_contents($this->other.'/.env', "DB_PASSWORD=bob\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_symlinked_project_directory_is_refused(): void
    {
        symlink($this->other, $this->homes.'/alice/project');

        $this->assertRefused($this->homes.'/alice/project/.env');
    }

    public function test_a_symlinked_directory_above_a_nested_file_is_refused(): void
    {
        mkdir($this->homes.'/alice/project');
        symlink($this->other, $this->homes.'/alice/project/server');

        $this->assertRefused($this->homes.'/alice/project/server/.env');
    }

    public function test_a_directory_is_not_created_below_a_symlinked_one(): void
    {
        mkdir($this->homes.'/alice/project');
        symlink($this->other, $this->homes.'/alice/project/server');

        $this->assertRefused($this->homes.'/alice/project/server/config/.env');
        $this->assertDirectoryDoesNotExist($this->other.'/config');
    }

    public function test_a_dot_dot_out_of_the_home_is_refused(): void
    {
        mkdir($this->homes.'/alice/project');

        $this->assertRefused($this->homes.'/alice/project/../../bob/project/.env');
    }

    public function test_a_real_nested_directory_is_still_written(): void
    {
        mkdir($this->homes.'/alice/project/server', 0777, true);

        $this->filesystem()->writeFileReplacingPath($this->homes.'/alice/project/./server//.env', "A=1\n", '1000:1000', '600');
        $this->filesystem()->writeFileReplacingPath($this->homes.'/alice/project/new/dir/.env', "B=2\n", '1000:1000', '600');

        $this->assertSame("A=1\n", file_get_contents($this->homes.'/alice/project/server/.env'));
        $this->assertSame("B=2\n", file_get_contents($this->homes.'/alice/project/new/dir/.env'));
    }

    /** For a step that runs ahead of the write, such as the account's own mkdir, which a dangling link fails with "File exists". */
    public function test_a_symlink_above_a_path_is_found_before_anything_is_written(): void
    {
        $project = $this->homes.'/alice/project';
        mkdir($project.'/real/sub', 0777, true);
        symlink($this->root.'/nowhere', $project.'/dangling');

        $this->filesystem()->assertNoSymlinkAbove($project.'/real/sub/.env', $project);
        $this->filesystem()->assertNoSymlinkAbove($project.'/new/dir/.env', $project);
        $this->filesystem()->assertNoSymlinkAbove($project.'/.env', $project);

        try {
            $this->filesystem()->assertNoSymlinkAbove($project.'/dangling/x/.env', $project);
            $this->fail('The link above the path was not found.');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                "Refusing to write '{$project}/dangling/x/.env': a directory above it is a symlink or leads out of the account's home.",
                $e->getMessage()
            );
        }
        $this->assertDirectoryDoesNotExist($project.'/dangling/x');
    }

    public function test_a_symlinked_directory_outside_the_homes_is_not_checked(): void
    {
        mkdir($this->root.'/engine/real', 0777, true);
        symlink($this->root.'/engine/real', $this->root.'/engine/current');

        $this->filesystem()->writeFileReplacingPath($this->root.'/engine/current/daemon.json', '{}');

        $this->assertSame('{}', file_get_contents($this->root.'/engine/real/daemon.json'));
    }

    private function assertRefused(string $path): void
    {
        try {
            $this->filesystem()->writeFileReplacingPath($path, "A=tenant\n", '1000:1000', '600');
            $this->fail("The write to {$path} went ahead.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('a directory above it is a symlink or leads out of the account', $e->getMessage());
        }
        $this->assertSame("DB_PASSWORD=bob\n", file_get_contents($this->other.'/.env'));
        $this->assertSame(['.', '..', '.env'], scandir($this->other));
        $this->assertSame(['.', '..'], scandir($this->homes.'/'.Filesystem::WRITE_STAGE_NAME));
    }

    private function filesystem(): Filesystem
    {
        $local = new LocalHostSystem($this->root, $this->homes);

        return new Filesystem(new class($local, $this->homes) extends System
        {
            public function __construct(private LocalHostSystem $local, private string $homes) {}

            public function homesDirPath(): string
            {
                return $this->homes;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return $this->local->exec($cmd, $env, $timeout);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return $this->local->runProcess($cmd, $env, $timeout);
            }
        });
    }
}
