<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\RuntimeSidecars;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Dind\Strategy\AccountSecrets;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * What runtimeSidecarsFromProject() says in the deploy log for a kept local-dev
 * stack, and what it falls back to with no compose file at all.
 */
class RuntimeSidecarsLogTest extends TestCase
{
    private string $username = '';

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'sidecarlog' . bin2hex(random_bytes(3));
        $username = $this->username;
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        $this->dir = sys_get_temp_dir() . '/pa-sidecarlog-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();
        parent::tearDown();
    }

    public function test_a_kept_local_dev_stack_reports_what_it_kept_dropped_and_moved(): void
    {
        file_put_contents($this->dir . '/docker-compose.yml', <<<'YAML'
        services:
          app:
            build: .
            environment:
              SECRET_KEY: notsosecret
            volumes:
              - .:/var/www/html
          mysql:
            image: mysql:8.0
            environment:
              MYSQL_ROOT_PASSWORD: hunter2
            volumes:
              - ./data/mysql:/var/lib/mysql
              - ./node_modules/x:/x
          proxy:
            image: caddy:2
            ports:
              - "80:80"
        YAML);

        [$result, $lines] = $this->sidecars();

        $this->assertSame(['mysql', 'proxy'], array_keys($result['services']), json_encode($lines));
        $this->assertSame([
            'Database sidecars on this account get their own passwords where the project sets none',
            'Keeping runtime services from compose: mysql, proxy',
            'Dropped the bind mysql: ./node_modules/x:/x: installed dependencies are not in a deployment\'s checkout',
            'Compose service app sets SECRET_KEY to a literal value: it is published in the repository and shared by every deploy of it',
            'Moved the datastore bind mysql: ./data/mysql:/var/lib/mysql to a named volume: its data does not belong in the checkout',
        ], $lines);
    }

    public function test_without_compose_a_database_url_on_localhost_still_gets_a_sidecar(): void
    {
        file_put_contents($this->dir . '/.env', "DATABASE_URL=postgres://app:secret@localhost:5432/app\n");

        [$result, $lines] = $this->sidecars();

        $this->assertNotSame([], $result['services']);
        $this->assertSame(
            'Adding backing services inferred from .env: ' . implode(', ', array_keys($result['services'])),
            end($lines)
        );
    }

    public function test_without_compose_or_env_there_are_no_sidecars(): void
    {
        [$result, $lines] = $this->sidecars();

        $this->assertSame(['services' => [], 'volumes' => [], 'env' => []], $result);
        $this->assertSame(['Database sidecars on this account get their own passwords where the project sets none'], $lines);
    }

    /** @return array{0: array<string, mixed>, 1: list<string>} */
    private function sidecars(): array
    {
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);
        $before = count($logger->entries());

        $system = new class () extends System {
            public function __construct()
            {
            }

            public function filesystem(): SystemFilesystem
            {
                return new class ($this) extends SystemFilesystem {
                    public function fileExists(string $path): bool
                    {
                        return is_file($path);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return (string) @file_get_contents($path);
                    }
                };
            }

            /** The account's daemon holds no volume yet. */
            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return LocalRoot::action((array) $cmd) === 'account-docker.listVolumes' ? '[]' : '';
            }
        };

        $model = new \App\Models\User();
        $model->username = $this->username;
        $model->details = ['UID' => 1001, 'GID' => 1001];

        $dind = $this->createStub(Dind::class);
        $dind->method('composeFilePath')->willReturn('/home/u/docker-compose.yml');
        $dind->method('username')->willReturn($this->username);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('userAppDirPath')->willReturn($this->dir);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('accountDaemon')->willReturnCallback(fn (): AccountDaemon => new AccountDaemon($dind));
        $dind->method('projectTree')->willReturnCallback(fn (): ProjectFiles => new ProjectFiles($dind));
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('declaredImagePorts')->willReturn([]);
        $dind->method('innerDocker')->willReturn($inner);
        $dind->method('strategy')->willReturn(new class ($dind) extends DeployStrategy {
            public function secrets(): AccountSecrets
            {
                return new class ($this) extends AccountSecrets {
                    public function __construct(object $unused)
                    {
                    }

                    public function for(string $purpose): string
                    {
                        return 'stub-seed:' . $purpose;
                    }
                };
            }
        });

        $result = (new RuntimeSidecars($dind))->runtimeSidecarsFromProject($this->dir);

        return [$result, array_values(array_map(
            static fn (array $entry): string => $entry['msg'],
            array_slice($logger->entries(), $before)
        ))];
    }
}
