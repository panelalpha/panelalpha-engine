<?php

namespace Tests\Unit\System\Project;

use App\Models\User;
use App\System;
use App\System\Project;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * fixPermissions() hands the home to the account but never a push's rollback
 * dumps, which the engine replays into the account's databases.
 */
class FixPermissionsPushDumpsTest extends TestCase
{
    use InMemoryDatabase;

    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->home = sys_get_temp_dir() . '/pa-fix-perms-' . bin2hex(random_bytes(6)) . '/live';
        mkdir($this->home, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->home)));
        parent::tearDown();
    }

    public function test_the_push_rollback_dumps_keep_their_owner(): void
    {
        $commands = $this->fixPermissions(['UID' => 1001, 'GID' => 1001]);

        $this->assertContains(sprintf(
            'sudo find %s -mindepth 1 -not \( -path %s -prune \) -exec chown -h 1001:1001 {} +',
            escapeshellarg($this->home),
            escapeshellarg($this->home . '/.pre-push-db'),
        ), $commands);
    }

    public function test_a_container_account_keeps_its_docker_tree_too(): void
    {
        $commands = $this->fixPermissions(['UID' => 1001, 'GID' => 1001, 'template' => 'dind']);

        $this->assertContains(sprintf(
            'sudo find %s -mindepth 1 -not \( -path %s -prune \) -not \( -path %s -prune \) -exec chown -h 1001:1001 {} +',
            escapeshellarg($this->home),
            escapeshellarg($this->home . '/.pre-push-db'),
            escapeshellarg($this->home . '/docker/*'),
        ), $commands);
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return list<string>
     */
    private function fixPermissions(array $details): array
    {
        $record = new \ArrayObject();
        $system = new class ($record) extends System {
            public function __construct(private \ArrayObject $record)
            {
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->record[] = is_array($cmd) ? implode(' ', $cmd) : $cmd;

                return new Process(['true']);
            }
        };
        $model = $this->makeUser('live', $details);

        (new class ($system, $model, $this->home) extends Project {
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
