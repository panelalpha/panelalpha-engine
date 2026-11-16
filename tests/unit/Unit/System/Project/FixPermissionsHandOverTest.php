<?php

namespace Tests\Unit\System\Project;

use App\Models\User;
use App\System;
use App\System\Project;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * fixPermissions() hands the whole home to the account; a container account's
 * inner Docker store stays the inner daemon's.
 */
class FixPermissionsHandOverTest extends TestCase
{
    use InMemoryDatabase;

    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->home = sys_get_temp_dir() . '/pa-fix-perms-' . bin2hex(random_bytes(6)) . '/live';
        mkdir($this->home, 0777, true);
        putenv('PA_ROOT_LOCAL_ROOTS=' . json_encode(['homes' => dirname($this->home)]));
    }

    protected function tearDown(): void
    {
        putenv('PA_ROOT_LOCAL_ROOTS');
        exec('rm -rf ' . escapeshellarg(dirname($this->home)));
        parent::tearDown();
    }

    public function test_a_classic_account_gets_its_whole_home(): void
    {
        $commands = $this->fixPermissions(['UID' => 1001, 'GID' => 1001]);

        $this->assertContains(sprintf('find %s -mindepth 1 -exec chown -h 1001:1001 {} +', $this->home), $commands);
    }

    public function test_a_container_account_keeps_its_docker_tree_out(): void
    {
        $commands = $this->fixPermissions(['UID' => 1001, 'GID' => 1001, 'template' => 'dind']);

        $this->assertContains(sprintf(
            'find %s -mindepth 1 -not ( -path %s -prune ) -exec chown -h 1001:1001 {} +',
            $this->home,
            $this->home . '/docker/*',
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
                $this->record[] = is_array($cmd) ? implode(' ', LocalRoot::plain($cmd)) : $cmd;

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
