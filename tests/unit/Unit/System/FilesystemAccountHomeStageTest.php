<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\System\Project\LocalHostSystem;

/**
 * A write into an account's home is staged, owned and moded in a root-only
 * directory beside the homes and only then renamed into place. A chown or
 * chmod that named a path inside the home would follow a symlink the tenant
 * swapped in between two commands.
 */
class FilesystemAccountHomeStageTest extends TestCase
{
    private string $root;

    private string $homes;

    /** @var list<list<string>> */
    private array $commands = [];

    private ?string $devices = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pa-fs-stage-'.bin2hex(random_bytes(4));
        $this->homes = $this->root.'/home';
        mkdir($this->homes.'/alice/project', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_write_into_a_home_never_chowns_or_chmods_a_path_in_the_home(): void
    {
        $path = $this->homes.'/alice/project/nested/dir/docker-compose.panelalpha.yml';

        $this->filesystem()->writeFileReplacingPath($path, "services: {}\n", '1000:1000', '600');

        $this->assertSame("services: {}\n", file_get_contents($path));
        $this->assertSame('600', sprintf('%o', fileperms($path) & 0777));
        $this->assertDirectoryExists($this->homes.'/alice/project/nested/dir');
        $this->assertNotEmpty($this->ownershipCommands());
        foreach ($this->ownershipCommands() as $argv) {
            $this->assertStringStartsWith($this->homes.'/'.Filesystem::WRITE_STAGE_NAME.'/', (string) end($argv), implode(' ', $argv));
        }
    }

    public function test_the_write_stage_is_root_only_and_left_empty(): void
    {
        $this->filesystem()->writeFileReplacingPath($this->homes.'/alice/project/.env', "A=1\n", '1000:1000', '600');

        $stage = $this->homes.'/'.Filesystem::WRITE_STAGE_NAME;
        $this->assertSame('700', sprintf('%o', fileperms($stage) & 0777));
        $this->assertSame(['.', '..'], scandir($stage));
    }

    public function test_a_symlink_in_the_home_is_replaced_and_its_target_untouched(): void
    {
        $outside = $this->root.'/victim.txt';
        file_put_contents($outside, 'victim');
        chmod($outside, 0644);
        $path = $this->homes.'/alice/project/.env';
        symlink($outside, $path);

        $this->filesystem()->writeFileReplacingPath($path, "A=1\n", '1000:1000', '600');

        $this->assertSame('victim', file_get_contents($outside));
        $this->assertSame('644', sprintf('%o', fileperms($outside) & 0777));
        $this->assertFalse(is_link($path));
        $this->assertSame("A=1\n", file_get_contents($path));
    }

    public function test_a_home_on_another_filesystem_is_refused_rather_than_copied_across(): void
    {
        $outside = $this->root.'/victim.txt';
        file_put_contents($outside, 'victim');
        $path = $this->homes.'/alice/project/.env';
        symlink($outside, $path);
        $this->devices = "1\n2\n";

        try {
            $this->filesystem()->writeFileReplacingPath($path, "A=1\n", '1000:1000', '600');
            $this->fail('A write across filesystems went ahead.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('is not on the filesystem of', $e->getMessage());
        }

        $this->assertSame('victim', file_get_contents($outside));
        $this->assertTrue(is_link($path));
        $this->assertSame([], $this->ownershipCommands());
    }

    public function test_a_path_outside_the_homes_is_still_staged_next_to_it(): void
    {
        $dir = $this->root.'/engine';
        mkdir($dir);

        $this->filesystem()->writeFileReplacingPath($dir.'/daemon.json', '{}', null, '644');

        $this->assertSame('{}', file_get_contents($dir.'/daemon.json'));
        $this->assertDirectoryDoesNotExist($this->homes.'/'.Filesystem::WRITE_STAGE_NAME);
    }

    /** @return list<list<string>> */
    private function ownershipCommands(): array
    {
        return array_values(array_filter(
            $this->commands,
            static fn (array $argv): bool => in_array($argv[1] ?? '', ['chown', 'chmod'], true)
        ));
    }

    private function filesystem(): Filesystem
    {
        $local = new LocalHostSystem($this->root, $this->homes);
        $commands = &$this->commands;
        $devices = &$this->devices;

        return new Filesystem(new class($local, $this->homes, $commands, $devices) extends System
        {
            /** @param list<list<string>> $commands */
            public function __construct(
                private LocalHostSystem $local,
                private string $homes,
                private array &$commands,
                private ?string &$devices,
            ) {}

            public function homesDirPath(): string
            {
                return $this->homes;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->commands[] = (array) $cmd;

                return $this->local->exec($cmd, $env, $timeout);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = (array) $cmd;
                if ($this->devices !== null && array_slice((array) $cmd, 0, 4) === ['sudo', 'stat', '-c', '%d']) {
                    $process = new Process(['printf', '%s', $this->devices]);
                    $process->run();

                    return $process;
                }

                return $this->local->runProcess($cmd, $env, $timeout);
            }
        });
    }
}
