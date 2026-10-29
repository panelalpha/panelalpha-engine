<?php

namespace Tests\Unit\System\Project;

use App\Models\User;
use App\System;
use App\System\Project;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * fixPermissions() names a few fixed directories of the home on chown and chmod
 * command lines, which follow a link: a link there is left alone.
 */
class FixPermissionsLinksTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    private string $home;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir().'/pa-fix-perms-links-'.bin2hex(random_bytes(6));
        $this->home = $this->root.'/live';
        $this->outside = $this->root.'/outside';
        mkdir($this->home, 0755, true);
        mkdir($this->outside, 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_linked_panelalpha_directory_is_never_chmodded(): void
    {
        symlink($this->outside, $this->home.'/.panelalpha');

        $commands = $this->fixPermissions(['UID' => 1001, 'GID' => 1001]);

        $this->assertNotContains('sudo chmod 700 '.$this->home.'/.panelalpha', $commands);
    }

    public function test_a_real_panelalpha_directory_is_still_locked_down(): void
    {
        mkdir($this->home.'/.panelalpha');

        $commands = $this->fixPermissions(['UID' => 1001, 'GID' => 1001]);

        $this->assertContains('sudo chmod 700 '.$this->home.'/.panelalpha', $commands);
    }

    public function test_a_container_accounts_linked_docker_directory_is_never_chowned(): void
    {
        symlink($this->outside, $this->home.'/docker');

        $commands = $this->fixPermissions(['UID' => 1001, 'GID' => 1001, 'template' => 'dind']);

        $this->assertNotContains('sudo chown root:root '.$this->home.'/docker', $commands);
    }

    public function test_a_linked_lscache_directory_is_never_chowned(): void
    {
        mkdir($this->home.'/live.example.test');
        symlink($this->outside, $this->home.'/live.example.test/.lscache');

        $commands = $this->fixPermissions(['UID' => 1001, 'GID' => 1001], 'live.example.test');

        $this->assertNotContains('sudo chown -R nobody:1001 '.$this->home.'/live.example.test/.lscache', $commands);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return list<string>
     */
    private function fixPermissions(array $details, ?string $domain = null): array
    {
        $record = new \ArrayObject;
        $system = new class($record) extends System
        {
            public function __construct(private \ArrayObject $record) {}

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
                $this->record[] = implode(' ', $argv);
                $process = new Process(array_slice($argv, 0, 2) === ['sudo', 'test'] ? array_slice($argv, 1) : ['true']);
                $process->run();

                return $process;
            }
        };
        $model = $this->makeUser('live', $details);
        if ($domain !== null) {
            $this->makeMainDomain($model, $domain);
        }

        (new class($system, $model->fresh(), $this->home) extends Project
        {
            public function __construct(System $system, User $model, private string $home)
            {
                parent::__construct($system, $model);
            }

            public function homeDirPath(): string
            {
                return $this->home;
            }
        })->fixPermissions();

        return $record->getArrayCopy();
    }
}
