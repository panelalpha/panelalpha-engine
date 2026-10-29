<?php

namespace Tests\Unit\System\Project\Dind;

use App\Models\User;
use App\System\Project\Dind\AppDatabase;
use App\System\Project\Dind\AppDatabaseCopy;
use Illuminate\Support\Facades\Facade;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The application database the deploy provisioned on the account MySQL server
 * travels with a staging, clone or push: the copy gets its own, under its own
 * prefix, and never runs on the source's.
 */
class AppDatabaseCopyTest extends TestCase
{
    use InMemoryDatabase;

    private RecordingAppMysql $mysql;

    private string $rollbackDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        Facade::clearResolvedInstances();
        $this->mysql = new RecordingAppMysql();
        $this->rollbackDir = sys_get_temp_dir() . '/pa-app-db-copy-' . bin2hex(random_bytes(4));
        mkdir($this->rollbackDir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->rollbackDir));
        parent::tearDown();
    }

    public function test_a_copy_gets_its_own_database_holding_the_sources_data(): void
    {
        $live = $this->withAppDatabase($this->user('live'));
        $stg = $this->user('stg');

        $result = $this->copy()->intoCopy($live, $stg, true);

        $this->assertSame(['live_app' => 'stg_app'], $result?->databases);
        $this->assertSame(['create stg_app', 'copy live_app to stg_app'], $this->mysql->log);
        $this->assertSame(['stg_app'], $stg->mysqlDatabases()->pluck('database')->all());
        $this->assertSame(['stg_app'], $stg->mysqlUsers()->pluck('user')->all());
        $password = AppDatabase::credentialsOf($stg->refresh())['password'] ?? null;
        $this->assertIsString($password);
        $this->assertNotSame('live-secret', $password);
        $this->assertSame($password, $this->mysql->users['stg_app']);
        $this->assertContains(['stg_app', 'stg_app', 'ALL PRIVILEGES'], $this->mysql->grants);
    }

    public function test_a_copy_without_databases_still_gets_an_empty_database_of_its_own(): void
    {
        $live = $this->withAppDatabase($this->user('live'));
        $stg = $this->user('stg');

        $result = $this->copy()->intoCopy($live, $stg, false);

        $this->assertSame([], $result?->databases);
        $this->assertSame(['create stg_app'], $this->mysql->log);
        $this->assertTrue(AppDatabase::isOwnedBy($stg));
    }

    public function test_a_source_without_an_app_database_copies_nothing(): void
    {
        $live = $this->user('live');
        $live->mysqlDatabases()->create(['database' => 'live_wp']);

        $this->assertNull($this->copy()->intoCopy($live, $this->user('stg'), true));
        $this->assertSame([], $this->mysql->log);
    }

    public function test_a_push_replaces_the_targets_database_under_a_rollback_dump(): void
    {
        $stg = $this->withAppDatabase($this->user('stg'));
        $live = $this->withAppDatabase($this->user('live'));
        $this->mysql->grants[] = ['live_app', 'live_app', 'ALL PRIVILEGES'];
        $rollbacks = [];
        $provisioned = null;

        $result = $this->copy()->intoTarget($stg, $live, $this->rollbackDir, $rollbacks, $provisioned);

        $this->assertSame(['stg_app' => 'live_app'], $result->databases);
        $this->assertSame(['live_app' => $this->rollbackDir . '/live_app.sql.gz'], $rollbacks);
        $this->assertNull($provisioned);
        $this->assertSame(['dump live_app', 'drop live_app', 'create live_app', 'copy stg_app to live_app'], $this->mysql->log);
        $this->assertSame('live-secret', AppDatabase::credentialsOf($live->refresh())['password'] ?? null);
    }

    public function test_a_push_into_a_target_without_one_provisions_it_and_records_it(): void
    {
        $stg = $this->withAppDatabase($this->user('stg'));
        $live = $this->user('live');
        $rollbacks = [];
        $provisioned = null;

        $this->copy()->intoTarget($stg, $live, $this->rollbackDir, $rollbacks, $provisioned);

        $this->assertSame([], $rollbacks);
        $this->assertSame('live_app', $provisioned?->name);
        $this->assertSame(['create live_app', 'drop live_app', 'create live_app', 'copy stg_app to live_app'], $this->mysql->log);
        $this->assertArrayHasKey('live_app', $this->mysql->users);
    }

    public function test_removing_what_a_push_provisioned_leaves_no_database_user_grant_or_password(): void
    {
        $stg = $this->withAppDatabase($this->user('stg'));
        $live = $this->user('live');
        $rollbacks = [];
        $provisioned = null;
        $this->copy()->intoTarget($stg, $live, $this->rollbackDir, $rollbacks, $provisioned);

        $provisioned?->remove($live);

        $this->assertNotContains('live_app', $this->mysql->databases);
        $this->assertArrayNotHasKey('live_app', $this->mysql->users);
        $this->assertSame([], array_values(array_filter($this->mysql->grants, static fn (array $g): bool => $g[0] === 'live_app')));
        $live = $live->refresh();
        $this->assertSame([], $live->mysqlDatabases()->pluck('database')->all());
        $this->assertSame([], $live->mysqlUsers()->pluck('user')->all());
        $this->assertNull(AppDatabase::credentialsOf($live));
    }

    public function test_removing_what_a_push_provisioned_keeps_a_user_and_password_the_target_already_had(): void
    {
        $live = $this->user('live');
        $live->mysqlUsers()->create(['user' => 'live_app']);
        $live->setDetails([AppDatabase::PASSWORD_DETAIL => 'live-secret']);
        $live->save();
        $this->mysql->users['live_app'] = 'live-secret';
        $provisioned = null;
        $this->copy()->ensureTargetHasOne($live, $provisioned);

        $provisioned?->remove($live);

        $this->assertNotContains('live_app', $this->mysql->databases);
        $this->assertArrayHasKey('live_app', $this->mysql->users);
        $this->assertContains('revoke live_app on live_app', $this->mysql->log);
        $this->assertSame([], array_values(array_filter($this->mysql->grants, static fn (array $g): bool => $g[0] === 'live_app')));
        $live = $live->refresh();
        $this->assertSame([], $live->mysqlDatabases()->pluck('database')->all());
        $this->assertSame(['live_app'], $live->mysqlUsers()->pluck('user')->all());
        $this->assertSame('live-secret', AppDatabase::credentialsOf($live)['password'] ?? null);
    }

    public function test_removing_what_a_files_only_push_provisioned_keeps_a_database_the_server_already_had(): void
    {
        $live = $this->user('live');
        $this->mysql->databases[] = 'live_app';
        $provisioned = null;
        $this->copy()->ensureTargetHasOne($live, $provisioned);

        $provisioned?->remove($live);

        $this->assertSame(['live_app'], $this->mysql->databases);
        $this->assertNotContains('drop live_app', $this->mysql->log);
        $this->assertArrayNotHasKey('live_app', $this->mysql->users);
        $this->assertSame([], $live->refresh()->mysqlDatabases()->pluck('database')->all());
    }

    public function test_removing_what_a_push_provisioned_drops_a_database_it_replaced_with_the_sources_data(): void
    {
        $stg = $this->withAppDatabase($this->user('stg'));
        $live = $this->user('live');
        $this->mysql->databases[] = 'live_app';
        $rollbacks = [];
        $provisioned = null;
        $this->copy()->intoTarget($stg, $live, $this->rollbackDir, $rollbacks, $provisioned);

        $provisioned?->remove($live);

        $this->assertNotContains('live_app', $this->mysql->databases);
    }

    public function test_a_target_that_already_has_one_records_nothing_to_remove(): void
    {
        $live = $this->withAppDatabase($this->user('live'));
        $provisioned = null;

        $this->copy()->ensureTargetHasOne($live, $provisioned);

        $this->assertNull($provisioned);
        $this->assertSame([], $this->mysql->log);
    }

    private function copy(): AppDatabaseCopy
    {
        return new AppDatabaseCopy($this->mysql);
    }

    private function user(string $username): User
    {
        return $this->makeUser($username, ['template' => 'dind', 'mysql_prefix' => $username . '_']);
    }

    private function withAppDatabase(User $user): User
    {
        $name = AppDatabase::nameFor($user);
        $user->mysqlDatabases()->create(['database' => $name]);
        $user->mysqlUsers()->create(['user' => $name]);
        $user->setDetails([AppDatabase::PASSWORD_DETAIL => $user->username . '-secret']);
        $user->save();
        $this->mysql->databases[] = $name;
        $this->mysql->users[$name] = $user->username . '-secret';

        return $user->refresh();
    }
}
