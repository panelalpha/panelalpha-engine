<?php

namespace Tests\Unit\System;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Dind\AppDatabase;
use App\System\Project\Dind\CopyVolumes;
use App\System\Projects;
use App\System\Services\Mysql;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;
use Tests\Unit\System\Project\Dind\RecordingAppMysql;

/**
 * Pushing a container project whose deploy gave it an application database on
 * the account MySQL server: the target's database takes the source's data
 * under a rollback dump, and the target keeps its own database and address in
 * the run file the push brings over.
 */
class ProjectsContainerAppDatabasePushTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    private RecordingAppMysql $mysql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        Facade::clearResolvedInstances();
        $this->mysql = new RecordingAppMysql;
        $this->root = sys_get_temp_dir().'/pa-push-app-db-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/live/project', 0777, true);
        mkdir($this->root.'/stg/project', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_the_target_gets_the_sources_data_and_keeps_its_own_database_and_address(): void
    {
        [$stg, $live] = $this->stagingPair();
        $this->writeRunFile('stg', $stg);
        $this->writeRunFile('live', $live);

        (new Projects($this->system()))->pushToApp('stg', 'live');

        $this->assertSame(['dump live_app', 'drop live_app', 'create live_app', 'copy stg_app to live_app'], $this->mysql->log);
        $env = Yaml::parseFile($this->root.'/live/project/'.EngineArtifacts::RUN_COMPOSE)['services']['app']['environment'];
        $this->assertSame('live_app', $env['DB_DATABASE']);
        $this->assertSame('live_app', $env['DB_USERNAME']);
        $this->assertSame('live-secret', $env['DB_PASSWORD']);
        $this->assertSame('https://live.example.test', $env['APP_URL']);
        $this->assertSame('pushed', $env['MARKER']);
        $this->assertSame('1001:1001', Yaml::parseFile($this->root.'/live/project/'.EngineArtifacts::RUN_COMPOSE)['services']['app']['user']);
        $this->assertStringContainsString("app:x:1001:1001:app:/app:/usr/sbin/nologin\n", (string) file_get_contents($this->root.'/live/project/'.EngineArtifacts::RUN_PASSWD));
        $this->assertSame("root:x:0:\napp:x:1001:\n", file_get_contents($this->root.'/live/project/'.EngineArtifacts::RUN_GROUP));
        $this->assertDirectoryDoesNotExist($this->root.'/live/.pre-push-db');
        $this->assertSame('completed', User::findByUsernameOrFail('live')->getDetails()['async_status']['push'] ?? null);
    }

    public function test_a_failed_push_restores_the_targets_database_from_its_dump(): void
    {
        $this->stagingPair();

        try {
            (new Projects($this->system(failStart: true)))->pushToApp('stg', 'live', files: false);
            $this->fail('the push should have failed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('start exploded', $e->getMessage());
        }

        $this->assertSame(
            ['dump live_app', 'drop live_app', 'create live_app', 'copy stg_app to live_app', 'restore live_app from live_app.sql.gz'],
            $this->mysql->log
        );
        $this->assertDirectoryDoesNotExist($this->root.'/live/.pre-push-db');
    }

    public function test_a_cleanup_failure_after_the_push_committed_keeps_the_database_it_created(): void
    {
        $stg = $this->containerProject('stg', 2002);
        $live = $this->makeUser('live', ['template' => 'dind', 'UID' => 1001, 'GID' => 1001, 'mysql_prefix' => 'live_'], 'live.example.test');
        $this->makeMainDomain($live, 'live.example.test');
        $stg->staging = $live->id;
        $stg->save();
        $this->writeRunFile('stg', $stg);
        $this->writeRunFile('live', $live);

        $result = (new Projects($this->system(failRemoving: $this->root.'/live/.pre-push')))->pushToApp('stg', 'live');

        $this->assertSame(['create live_app', 'drop live_app', 'create live_app', 'copy stg_app to live_app'], $this->mysql->log);
        $this->assertTrue(AppDatabase::isOwnedBy($live));
        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString('The push was applied, but removing '.$this->root.'/live/.pre-push failed', $result->warnings[0]);
        $this->assertSame('completed', User::findByUsernameOrFail('live')->getDetails()['async_status']['push'] ?? null);
    }

    public function test_a_cleanup_failure_after_the_push_committed_replays_no_rollback_dump(): void
    {
        [$stg, $live] = $this->stagingPair();
        $this->writeRunFile('stg', $stg);
        $this->writeRunFile('live', $live);

        $result = (new Projects($this->system(failRemoving: $this->root.'/live/.pre-push')))->pushToApp('stg', 'live');

        $this->assertSame(['dump live_app', 'drop live_app', 'create live_app', 'copy stg_app to live_app'], $this->mysql->log);
        $this->assertDirectoryDoesNotExist($this->root.'/live/.pre-push-db');
        $this->assertSame('pushed', Yaml::parseFile($this->root.'/live/project/'.EngineArtifacts::RUN_COMPOSE)['services']['app']['environment']['MARKER']);
        $this->assertCount(1, $result->warnings);
        $this->assertSame('completed', User::findByUsernameOrFail('live')->getDetails()['async_status']['push'] ?? null);
        $this->assertNull(User::findByUsernameOrFail('live')->getDetails()['error'] ?? null);
    }

    public function test_a_failed_push_into_a_target_without_one_removes_the_database_user_and_password_it_provisioned(): void
    {
        $this->pairWithoutTargetDatabase();

        $this->pushFails(files: false, databases: true);

        $this->assertNoAppDatabaseOnLive();
    }

    public function test_a_failed_files_only_push_removes_the_empty_database_it_provisioned(): void
    {
        [$stg, $live] = $this->pairWithoutTargetDatabase();
        $this->writeRunFile('stg', $stg);
        $this->writeRunFile('live', $live);

        $this->pushFails(files: true, databases: false);

        $this->assertContains('create live_app', $this->mysql->log);
        $this->assertNoAppDatabaseOnLive();
    }

    public function test_a_failed_files_only_push_keeps_a_database_the_server_already_had_under_that_name(): void
    {
        [$stg, $live] = $this->pairWithoutTargetDatabase();
        $this->writeRunFile('stg', $stg);
        $this->writeRunFile('live', $live);
        $this->mysql->databases[] = 'live_app';

        $this->pushFails(files: true, databases: false);

        $this->assertContains('live_app', $this->mysql->databases);
        $this->assertNotContains('drop live_app', $this->mysql->log);
        $this->assertArrayNotHasKey('live_app', $this->mysql->users);
        $this->assertSame([], User::findByUsernameOrFail('live')->mysqlDatabases()->pluck('database')->all());
    }

    public function test_a_project_whose_database_lives_in_its_volumes_touches_no_mysql(): void
    {
        $live = $this->makeUser('live', ['template' => 'dind', 'UID' => 1001, 'GID' => 1001, 'mysql_prefix' => 'live_']);
        $stg = $this->makeUser('stg', ['template' => 'dind', 'UID' => 2002, 'GID' => 2002, 'mysql_prefix' => 'stg_']);
        $stg->staging = $live->id;
        $stg->save();

        (new Projects($this->system()))->pushToApp('stg', 'live');

        $this->assertSame([], $this->mysql->log);
        $this->assertSame([], $live->refresh()->mysqlDatabases()->pluck('database')->all());
    }

    public function test_a_database_only_push_from_a_source_without_one_is_refused_before_anything_stops(): void
    {
        $live = $this->containerProject('live', 1001);
        $stg = $this->makeUser('stg', ['template' => 'dind', 'UID' => 2002, 'GID' => 2002, 'mysql_prefix' => 'stg_', 'deploy_strategy' => 'dockerfile']);
        $stg->staging = $live->id;
        $stg->save();
        $before = $live->refresh()->getDetails();

        try {
            (new Projects($this->system(failStart: true)))->pushToApp('stg', 'live', files: false);
            $this->fail('the push should have been refused');
        } catch (\RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringStartsWith('Nothing to push', $e->getMessage());
        }

        $this->assertSame([], $this->mysql->log);
        $this->assertSame($before, $live->refresh()->getDetails());
        $this->assertDirectoryDoesNotExist($this->root.'/live/.pre-push-db');
    }

    public function test_a_database_only_push_keeps_the_targets_deploy_settings(): void
    {
        [$stg, $live] = $this->stagingPair();
        $this->deployedAs($stg, 'dockerfile', 'stg-image', 'aaaa');
        $this->deployedAs($live, 'php', 'live-image', 'bbbb');

        (new Projects($this->system()))->pushToApp('stg', 'live', files: false);

        $details = User::findByUsernameOrFail('live')->getDetails();
        $this->assertSame(['php', 'live-image', 'bbbb'], [$details['deploy_strategy'], $details['deploy_image'], $details['git_commit']]);
        $this->assertContains('copy stg_app to live_app', $this->mysql->log);
    }

    public function test_a_push_with_files_takes_the_sources_deploy_settings(): void
    {
        [$stg, $live] = $this->stagingPair();
        $this->writeRunFile('stg', $stg);
        $this->writeRunFile('live', $live);
        $this->deployedAs($stg, 'dockerfile', 'stg-image', 'aaaa');
        $this->deployedAs($live, 'php', 'live-image', 'bbbb');

        (new Projects($this->system()))->pushToApp('stg', 'live');

        $details = User::findByUsernameOrFail('live')->getDetails();
        $this->assertSame(['dockerfile', 'stg-image', 'aaaa'], [$details['deploy_strategy'], $details['deploy_image'], $details['git_commit']]);
    }

    private function deployedAs(User $user, string $strategy, string $image, string $commit): void
    {
        $user->setDetails(['deploy_strategy' => $strategy, 'deploy_image' => $image, 'git_commit' => $commit]);
        $user->save();
    }

    private function pushFails(bool $files, bool $databases): void
    {
        try {
            (new Projects($this->system(failStart: true)))->pushToApp('stg', 'live', files: $files, databases: $databases);
            $this->fail('the push should have failed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('start exploded', $e->getMessage());
        }
    }

    private function assertNoAppDatabaseOnLive(): void
    {
        $live = User::findByUsernameOrFail('live');
        $this->assertNotContains('live_app', $this->mysql->databases);
        $this->assertArrayNotHasKey('live_app', $this->mysql->users);
        $this->assertSame([], array_values(array_filter($this->mysql->grants, static fn (array $g): bool => $g[0] === 'live_app')));
        $this->assertSame([], $live->mysqlDatabases()->pluck('database')->all());
        $this->assertSame([], $live->mysqlUsers()->pluck('user')->all());
        $this->assertNull(AppDatabase::credentialsOf($live));
        $this->assertSame('failed', $live->getDetails()['async_status']['push'] ?? null);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function pairWithoutTargetDatabase(): array
    {
        $live = $this->makeUser('live', ['template' => 'dind', 'UID' => 1001, 'GID' => 1001, 'mysql_prefix' => 'live_'], 'live.example.test');
        $this->makeMainDomain($live, 'live.example.test');
        $stg = $this->containerProject('stg', 2002);
        $stg->staging = $live->id;
        $stg->save();

        return [$stg->refresh(), $live->refresh()];
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function stagingPair(): array
    {
        $live = $this->containerProject('live', 1001);
        $stg = $this->containerProject('stg', 2002);
        $stg->staging = $live->id;
        $stg->save();

        return [$stg->refresh(), $live->refresh()];
    }

    private function containerProject(string $username, int $uid): User
    {
        $user = $this->makeUser($username, [
            'template' => 'dind',
            'UID' => $uid,
            'GID' => $uid,
            'mysql_prefix' => $username.'_',
            AppDatabase::PASSWORD_DETAIL => $username.'-secret',
        ], $username.'.example.test');
        $this->makeMainDomain($user, $username.'.example.test');
        $user->mysqlDatabases()->create(['database' => $username.'_app']);
        $user->mysqlUsers()->create(['user' => $username.'_app']);
        $this->mysql->databases[] = $username.'_app';
        $this->mysql->users[$username.'_app'] = $username.'-secret';

        return $user;
    }

    private function writeRunFile(string $home, User $user): void
    {
        $env = [
            'DB_HOST' => AppDatabase::hostname(),
            'DB_DATABASE' => $user->username.'_app',
            'DB_USERNAME' => $user->username.'_app',
            'DB_PASSWORD' => $user->username.'-secret',
            'APP_URL' => "https://{$user->username}.example.test",
            'MARKER' => $home === 'stg' ? 'pushed' : 'replaced',
        ];
        $ids = $user->getUid().':'.$user->getGid();
        $dir = "{$this->root}/{$home}/project/";
        file_put_contents(
            $dir.EngineArtifacts::RUN_COMPOSE,
            Yaml::dump(['services' => ['app' => ['image' => 'panelalpha/php:8.3', 'user' => $ids, 'environment' => $env]]], 6, 2)
        );
        file_put_contents($dir.EngineArtifacts::RUN_PASSWD, "root:x:0:0:root:/root:/bin/bash\napp:x:{$ids}:app:/app:/usr/sbin/nologin\n");
        file_put_contents($dir.EngineArtifacts::RUN_GROUP, "root:x:0:\napp:x:{$user->getGid()}:\n");
    }

    private function system(bool $failStart = false, ?string $failRemoving = null): System
    {
        return new class($this->root, $this->mysql, $failStart, $failRemoving) extends System
        {
            public function __construct(
                private string $root,
                private Mysql $server,
                private bool $failStart,
                private ?string $failRemoving,
            ) {}

            public function project(User $model): Project
            {
                return new class($this, $model, $this->root.'/'.$model->username, $this->failStart) extends Project
                {
                    public function __construct(System $system, User $model, private string $home, private bool $failStart)
                    {
                        parent::__construct($system, $model);
                    }

                    public function homeDirPath(): string
                    {
                        return $this->home;
                    }

                    public function copyVolumesForClone(): ?CopyVolumes
                    {
                        return null;
                    }

                    public function hasUserApp(): bool
                    {
                        return false;
                    }

                    public function start(): void
                    {
                        if ($this->failStart) {
                            throw new \RuntimeException('start exploded');
                        }
                    }

                    public function fixPermissions(): void {}
                };
            }

            public function mysql(): Mysql
            {
                return $this->server;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return $this->runProcess($cmd)->getOutput();
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return is_array($cmd) ? $this->apply(array_values($cmd)) : new PushAppDatabaseProcess(0);
            }

            /**
             * @param  list<string>  $argv
             */
            private function apply(array $argv): Process
            {
                $path = (string) end($argv);

                return match (array_slice($argv, 0, 3)) {
                    ['sudo', 'test', '-d'] => new PushAppDatabaseProcess(is_dir($path) ? 0 : 1),
                    ['sudo', 'test', '-e'] => new PushAppDatabaseProcess(file_exists($path) ? 0 : 1),
                    ['sudo', 'test', '-f'] => new PushAppDatabaseProcess(is_file($path) ? 0 : 1),
                    ['sudo', 'test', '-L'] => new PushAppDatabaseProcess(is_link($path) ? 0 : 1),
                    ['sudo', 'ls', '-A'] => new PushAppDatabaseProcess(0, implode("\n", array_diff(scandir($path) ?: [], ['.', '..']))),
                    ['sudo', 'df', '-P'] => new PushAppDatabaseProcess(0, "Filesystem 1024-blocks Used Available Capacity Mounted\n/dev/sda1 1 1 999999999 1% /"),
                    default => new PushAppDatabaseProcess($this->change($argv)),
                };
            }

            /**
             * @param  list<string>  $argv
             */
            private function change(array $argv): int
            {
                $args = array_values(array_filter(array_slice($argv, 2), static fn (string $a): bool => ! str_starts_with($a, '-')));
                if (($argv[1] ?? '') === 'rm' && in_array($this->failRemoving, $args, true)) {
                    return 1;
                }
                match ($argv[1] ?? '') {
                    'mkdir' => is_dir($args[0]) || mkdir($args[0], 0777, true),
                    'rm' => array_map(static fn (string $p) => exec('rm -rf '.escapeshellarg($p)), $args),
                    'rmdir' => rmdir($args[0]),
                    'rsync' => exec('cp -a '.escapeshellarg($args[0].'.').' '.escapeshellarg($args[1])),
                    'mv' => rename($args[0], str_ends_with($args[1], '/') ? $args[1].basename($args[0]) : $args[1]),
                    'cp' => copy($args[0], $args[1]),
                    'chmod' => chmod($args[1], (int) octdec($args[0])),
                    default => null,
                };

                return 0;
            }
        };
    }
}

class PushAppDatabaseProcess extends Process
{
    public function __construct(private int $code, private string $output = '')
    {
        parent::__construct(['true']);
    }

    public function isSuccessful(): bool
    {
        return $this->code === 0;
    }

    public function getExitCode(): ?int
    {
        return $this->code;
    }

    public function getErrorOutput(): string
    {
        return '';
    }

    public function getOutput(): string
    {
        return $this->output;
    }
}
