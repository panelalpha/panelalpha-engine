<?php

namespace Tests\Integration\System\Project\Backup;

use App\Support\JobBudget;
use App\Support\JobDeadline;
use App\System;
use App\System\Project;
use App\System\Project\Backup\DindRuntime;
use App\System\Project\Backup\HomeFiles;
use App\System\Project\Backup\ProjectContent;
use App\System\Project\Backup\ProjectDatabases;
use App\System\Project\Backup\ProjectFiles;
use App\System\Project\Backup\ProjectVolumes;
use App\System\Project\Dind;
use App\System\Project\Runtime;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

/**
 * A backup's named volumes go through the account's own daemon, each step on the job's window, a rollback on the reserve.
 */
class ProjectVolumesTest extends TestCase
{
    private const CONFIG = '{"services":{"db":{"volumes":[{"type":"volume","source":"dbdata","target":"/var/lib/mysql"}]}},'
        .'"volumes":{"dbdata":{"name":"shop_dbdata"}}}';

    /** @var list<array{0: string, 1: list<string>, 2: int}> */
    private array $asked = [];

    public function test_the_volumes_are_the_compose_configs_as_the_daemon_names_them(): void
    {
        $volumes = new ProjectVolumes($this->dindProject(), JobDeadline::startingNow());

        $this->assertSame([['name' => 'dbdata', 'docker_name' => 'shop_dbdata']], $volumes->names());
        $this->assertContains(['account-docker.volumes', ['test', 'shop_dbdata']], $this->actions());
    }

    public function test_a_volume_is_archived_on_the_steps_window(): void
    {
        $volumes = new ProjectVolumes($this->dindProject(), new JobDeadline(microtime(true) + 300));

        $volumes->archive('shop_dbdata', '/tmp/volume-dbdata.tar.gz');

        $run = $this->first('account-docker.run');
        $this->assertSame(['test', 'alpine:3.20', '2', 'shop_dbdata', '/data', 'ro'], array_slice($run[1], 0, 6));
        $this->assertGreaterThanOrEqual(299, $run[2]);
        $this->assertLessThanOrEqual(300, $run[2]);
    }

    public function test_a_volume_is_restored_writable_on_the_steps_window(): void
    {
        $volumes = new ProjectVolumes($this->dindProject(), new JobDeadline(microtime(true) + 300));

        $volumes->restore('shop_dbdata', '/tmp/volume-dbdata.tar.gz');

        $run = $this->first('account-docker.run');
        $this->assertSame(['shop_dbdata', '/data', 'rw'], array_slice($run[1], 3, 3));
        $this->assertGreaterThanOrEqual(299, $run[2]);
    }

    public function test_a_rollback_runs_on_the_cleanup_reserve(): void
    {
        $volumes = new ProjectVolumes($this->dindProject(), new JobDeadline(microtime(true) - 10));

        $volumes->rollback('shop_dbdata');

        $this->assertSame(JobBudget::CLEANUP_SECONDS, $this->first('account-docker.run')[2]);
    }

    public function test_a_missing_volume_is_created_in_the_accounts_daemon(): void
    {
        $volumes = new ProjectVolumes($this->dindProject(), JobDeadline::startingNow());

        $volumes->ensure('shop_dbdata');

        $this->assertNotSame([], array_filter($this->asked, static fn (array $call): bool => in_array('shop_dbdata', $call[1], true)));
    }

    public function test_a_backup_of_a_classic_account_is_refused(): void
    {
        $runtime = $this->createStub(Runtime::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Backup requires a DinD project');

        DindRuntime::of($this->project($runtime));
    }

    public function test_the_content_of_a_project_is_its_files_home_volumes_and_databases(): void
    {
        $content = ProjectContent::of($this->dindProject(), JobDeadline::startingNow());

        $this->assertInstanceOf(ProjectFiles::class, $content->files);
        $this->assertInstanceOf(HomeFiles::class, $content->home);
        $this->assertInstanceOf(ProjectDatabases::class, $content->databases);
        $content->volumes->rollback('shop_dbdata');
        $this->assertSame(['test', 'alpine:3.20'], array_slice($this->first('account-docker.run')[1], 0, 2));
    }

    /** @return list<array{0: string, 1: list<string>}> */
    private function actions(): array
    {
        return array_map(static fn (array $call): array => [$call[0], $call[1]], $this->asked);
    }

    /** @return array{0: string, 1: list<string>, 2: int} */
    private function first(string $action): array
    {
        foreach ($this->asked as $call) {
            if ($call[0] === $action) {
                return $call;
            }
        }

        $this->fail("{$action} was never asked for");
    }

    private function dindProject(): Project
    {
        return $this->project($this->standInDind());
    }

    private function project(Runtime $runtime): Project
    {
        return new class ($runtime) extends Project {
            public function __construct(private Runtime $standIn) {}

            public function runtime(): Runtime
            {
                return $this->standIn;
            }
        };
    }

    private function standInDind(): Dind
    {
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (array $argv, array $env = [], int $timeout = 600): string {
            $action = (string) LocalRoot::action($argv);
            $this->asked[] = [$action, LocalRoot::values($argv), $timeout];

            return match ($action) {
                'account-docker.volumes' => '[{"Name":"shop_dbdata","Driver":"local"}]',
                'account-docker.run' => '',
                default => self::CONFIG,
            };
        });

        return new class ($system) extends Dind {
            public function __construct(private System $host) {}

            public function system(): System
            {
                return $this->host;
            }

            public function username(): string
            {
                return 'test';
            }

            public function homeDirPath(): string
            {
                return '/home/test';
            }

            public function userAppComposeCommand(array $rest): array
            {
                return ['env', 'PWD=/home/test/project', 'docker', 'compose', '--project-directory', '/home/test/project', ...$rest];
            }
        };
    }
}
