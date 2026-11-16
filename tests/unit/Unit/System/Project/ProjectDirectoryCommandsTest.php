<?php

namespace Tests\Unit\System\Project;

use App\Models\User;
use App\System;
use App\System\Project;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

/**
 * The home and engine directories are created and removed with argv, so a
 * path the shell would split or interpret reaches the root broker as one value.
 * Only classic hosting gets `.wp-cli`: a container project's WP-CLI never reads it.
 */
class ProjectDirectoryCommandsTest extends TestCase
{
    private const USERNAME = 'a b;touch pwned';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-project-dirs-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/homes/' . self::USERNAME, 0700, true);
        mkdir($this->root . '/engine/users/' . self::USERNAME, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_creating_the_directories_passes_each_path_as_one_argument(): void
    {
        $system = $this->system();

        $this->project($system)->createDirectories();

        $home = $this->root . '/homes/' . self::USERNAME;
        $this->assertSame([
            [LocalRoot::CLIENT, 'files.makeDirectories', '--', $home],
            [LocalRoot::CLIENT, 'files.changeOwner', '--', 'root:root', $home],
            [LocalRoot::CLIENT, 'files.makeDirectories', '--', $home . '/.panelalpha'],
            [LocalRoot::CLIENT, 'files.changeMode', '--', '700', $home . '/.panelalpha'],
            [LocalRoot::CLIENT, 'files.makeDirectories', '--', $home . '/.wp-cli'],
            [LocalRoot::CLIENT, 'files.makeDirectories', '--', $this->root . '/engine/users/' . self::USERNAME],
            [LocalRoot::CLIENT, 'files.changeOwnerRecursively', '--', 'www-data:www-data', $this->root . '/engine/users/' . self::USERNAME],
        ], $system->commands);
    }

    public function test_a_container_project_gets_no_wp_cli_directory(): void
    {
        $system = $this->system();

        $this->project($system, ['template' => 'dind'])->createDirectories();

        $home = $this->root . '/homes/' . self::USERNAME;
        $this->assertSame([
            [LocalRoot::CLIENT, 'files.makeDirectories', '--', $home],
            [LocalRoot::CLIENT, 'files.changeOwner', '--', 'root:root', $home],
            [LocalRoot::CLIENT, 'files.makeDirectories', '--', $home . '/.panelalpha'],
            [LocalRoot::CLIENT, 'files.changeMode', '--', '700', $home . '/.panelalpha'],
            [LocalRoot::CLIENT, 'files.makeDirectories', '--', $this->root . '/engine/users/' . self::USERNAME],
            [LocalRoot::CLIENT, 'files.changeOwnerRecursively', '--', 'www-data:www-data', $this->root . '/engine/users/' . self::USERNAME],
        ], $system->commands);
    }

    public function test_deleting_the_directories_passes_each_path_as_one_argument(): void
    {
        $system = $this->system();

        $this->project($system)->tearDownLinuxIsolation();

        $this->assertSame([
            [LocalRoot::CLIENT, 'files.removeTree', '--', $this->root . '/homes/' . self::USERNAME],
            [LocalRoot::CLIENT, 'files.removeTree', '--', $this->root . '/engine/users/' . self::USERNAME],
        ], $system->commands);
    }

    /** @param array<string, string> $details */
    private function project(System $system, array $details = []): Project
    {
        $user = new User();
        $user->username = self::USERNAME;
        $user->setDetails($details);

        return new Project($system, $user);
    }

    private function system(): DirectoryRecordingSystem
    {
        return new DirectoryRecordingSystem($this->root);
    }
}

/** @internal */
final class DirectoryRecordingSystem extends System
{
    /** @var list<string|list<string>> */
    public array $commands = [];

    public function __construct(private string $root)
    {
    }

    public function engineDirPath(): string
    {
        return $this->root . '/engine';
    }

    public function homesDirPath(): string
    {
        return $this->root . '/homes';
    }

    public function isUidExists(string $username): bool
    {
        return false;
    }

    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        $this->commands[] = $cmd;

        return '';
    }
}
