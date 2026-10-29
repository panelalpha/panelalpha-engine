<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\System\Project\LocalHostSystem;

/**
 * The engine reads an account's files as root and copies what it read into
 * files the tenant owns. A symlink, a symlinked directory or a `..` the
 * tenant planted must not turn that read into one of another account's file.
 */
class FilesystemAccountHomeReadTest extends TestCase
{
    private string $root;

    private string $homes;

    private string $secret;

    /** @var list<list<string>> */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pa-fs-read-'.bin2hex(random_bytes(4));
        $this->homes = $this->root.'/home';
        mkdir($this->homes.'/alice/project/server', 0777, true);
        mkdir($this->homes.'/bob/project', 0777, true);
        $this->secret = $this->homes.'/bob/project/.env';
        file_put_contents($this->secret, "DB_PASSWORD=bob-secret\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_regular_file_in_a_home_is_read_without_following_a_link(): void
    {
        file_put_contents($this->homes.'/alice/project/.env', "A=1\n");

        $this->assertSame("A=1\n", $this->filesystem()->fileGetContents($this->homes.'/alice/project/.env'));
        $this->assertSame(['sudo', 'python3', '-I'], array_slice($this->readCommand(), 0, 3));
        $this->assertSame(['read', $this->homes.'/alice', $this->homes.'/alice/project/.env'], array_slice($this->readCommand(), 4));
    }

    /**
     * Root opening a temp file www-data owns in the sticky /tmp is refused under
     * fs.protected_regular, which failed every read on such a host.
     */
    public function test_a_read_in_a_home_goes_to_stdout_and_opens_no_file_for_writing(): void
    {
        file_put_contents($this->homes.'/alice/project/.env', "A=1\n");

        $this->filesystem()->fileGetContents($this->homes.'/alice/project/.env');

        foreach ($this->commands as $argv) {
            $this->assertSame([], preg_grep('/^of=/', $argv), implode(' ', $argv));
            $this->assertNotSame('cp', $argv[1] ?? null, implode(' ', $argv));
        }
    }

    public function test_a_symlink_to_another_accounts_file_is_refused(): void
    {
        symlink($this->secret, $this->homes.'/alice/project/.env.example');

        $this->assertRefused($this->homes.'/alice/project/.env.example');
    }

    public function test_a_symlink_to_a_file_outside_every_home_is_refused(): void
    {
        file_put_contents($this->root.'/core-secret', 'APP_KEY=core');
        symlink($this->root.'/core-secret', $this->homes.'/alice/project/docker-compose.yml');

        $this->assertRefused($this->homes.'/alice/project/docker-compose.yml');
    }

    public function test_a_file_below_a_symlinked_directory_is_refused(): void
    {
        rmdir($this->homes.'/alice/project/server');
        symlink($this->homes.'/bob/project', $this->homes.'/alice/project/server');

        $this->assertRefused($this->homes.'/alice/project/server/.env');
    }

    public function test_a_dot_dot_out_of_the_home_is_refused(): void
    {
        $this->assertRefused($this->homes.'/alice/project/../../bob/project/.env');
    }

    public function test_a_symlink_that_stays_inside_the_same_home_is_still_read(): void
    {
        file_put_contents($this->homes.'/alice/project/compose.prod.yml', "services: {}\n");
        symlink('compose.prod.yml', $this->homes.'/alice/project/docker-compose.yml');

        $this->assertSame("services: {}\n", $this->filesystem()->fileGetContents($this->homes.'/alice/project/docker-compose.yml'));
    }

    public function test_cat_answers_null_for_a_symlink_out_of_the_home(): void
    {
        symlink($this->secret, $this->homes.'/alice/project/docker-compose.panelalpha.yml');
        file_put_contents($this->homes.'/alice/project/.env', "A=1\n");

        $this->assertNull($this->filesystem()->cat($this->homes.'/alice/project/docker-compose.panelalpha.yml'));
        $this->assertSame("A=1\n", $this->filesystem()->cat($this->homes.'/alice/project/.env'));
    }

    public function test_a_path_outside_the_homes_is_still_copied_as_before(): void
    {
        file_put_contents($this->root.'/engine.json', '{}');

        $this->assertSame('{}', $this->filesystem()->fileGetContents($this->root.'/engine.json'));
        $this->assertSame('cp', $this->readCommand()[1]);
    }

    private function assertRefused(string $path): void
    {
        try {
            $contents = $this->filesystem()->fileGetContents($path);
            $this->fail('Read through to: '.$contents);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to read', $e->getMessage());
        }
        $this->assertNull($this->readCommandOrNull());
    }

    /** @return list<string> */
    private function readCommand(): array
    {
        $argv = $this->readCommandOrNull();
        $this->assertNotNull($argv, 'nothing was read');

        return $argv;
    }

    /** @return ?list<string> */
    private function readCommandOrNull(): ?array
    {
        foreach ($this->commands as $argv) {
            if (($argv[1] ?? '') === 'cp' || ($argv[4] ?? '') === 'read') {
                return $argv;
            }
        }

        return null;
    }

    private function filesystem(): Filesystem
    {
        $local = new LocalHostSystem($this->root, $this->homes);
        $commands = &$this->commands;

        return new Filesystem(new class($local, $this->homes, $commands) extends System
        {
            /** @param list<list<string>> $commands */
            public function __construct(
                private LocalHostSystem $local,
                private string $homes,
                private array &$commands,
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

                return $this->local->runProcess($cmd, $env, $timeout);
            }
        });
    }
}
