<?php

namespace Tests\Integration\Http\Mysql;

use App\Http\Middleware\Authenticate;
use App\Models\MysqlDatabase;
use App\Models\MysqlUser;
use App\Models\User;
use App\System;
use App\System\Services\Mysql;
use App\System\Project\Dind\AppDatabase;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingMysql;
use Tests\TestCase;

/** The MySQL database, user and privilege endpoints: the statement each one sends to sites-db, and the record it keeps. */
class MysqlAccountStatementsHttpTest extends TestCase
{
    use InMemoryDatabase;

    private User $shop;

    private RecordingMysql $mysql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->withoutMiddleware(Authenticate::class);
        $this->shop = $this->makeUser('shop', ['mysql_prefix' => 'shop_']);
        $this->mysql = new RecordingMysql();
        $mysql = $this->mysql;
        $this->app->instance(System::class, new class($mysql) extends System
        {
            public function __construct(private readonly RecordingMysql $recording) {}

            public function mysql(): Mysql
            {
                return $this->recording;
            }
        });
    }

    public function test_databases_are_listed_with_their_size(): void
    {
        $this->shop->mysqlDatabases()->create(['database' => 'shop_main']);
        $this->mysql->sizes = ['shop_main' => 4096];

        $this->getJson('/api/projects/shop/mysql/databases')
            ->assertOk()
            ->assertJsonPath('data.0.database', 'shop_main')
            ->assertJsonPath('data.0.size_bytes', 4096);
    }

    public function test_a_database_is_created_with_the_project_prefix_and_recorded(): void
    {
        $this->postJson('/api/projects/shop/mysql/databases', ['name' => 'blog'])
            ->assertCreated()
            ->assertJsonPath('data.database', 'shop_blog');

        $this->assertSame([['createDatabase', 'shop_blog']], $this->mysql->recorded());
        $this->assertTrue(MysqlDatabase::query()->where('database', 'shop_blog')->exists());
    }

    public function test_a_deleted_database_is_dropped_and_revoked_from_every_user(): void
    {
        $this->shop->mysqlDatabases()->create(['database' => 'shop_main']);
        $this->shop->mysqlUsers()->create(['user' => 'shop_one']);
        $this->shop->mysqlUsers()->create(['user' => 'shop_two']);

        $this->deleteJson('/api/projects/shop/mysql/databases/main')
            ->assertOk()
            ->assertJsonPath('data.database', 'shop_main');

        $this->assertSame([
            ['deleteDatabase', 'shop_main'],
            ['revokeIfExists', 'shop_one', 'shop_main'],
            ['revokeIfExists', 'shop_two', 'shop_main'],
        ], $this->mysql->recorded());
        $this->assertFalse(MysqlDatabase::query()->where('database', 'shop_main')->exists());
    }

    public function test_users_are_listed_with_the_databases_they_are_granted(): void
    {
        $this->shop->mysqlUsers()->create(['user' => 'shop_one']);
        $this->mysql->grants = ['shop_one' => ['shop_main' => 'SELECT', 'shop_blog' => 'ALL PRIVILEGES']];

        $this->getJson('/api/projects/shop/mysql/users')
            ->assertOk()
            ->assertJsonPath('data.0.user', 'shop_one')
            ->assertJsonPath('data.0.databases', ['shop_main', 'shop_blog']);
    }

    public function test_a_user_is_created_with_the_project_prefix_and_recorded(): void
    {
        $this->postJson('/api/projects/shop/mysql/users', ['name' => 'writer', 'password' => 'long-enough-1'])
            ->assertCreated()
            ->assertJsonPath('data.user', 'shop_writer');

        $this->assertSame([['createUser', 'shop_writer', 'long-enough-1']], $this->mysql->recorded());
        $this->assertTrue(MysqlUser::query()->where('user', 'shop_writer')->exists());
    }

    public function test_a_deleted_user_is_dropped_and_forgotten(): void
    {
        $this->shop->mysqlUsers()->create(['user' => 'shop_one']);

        $this->deleteJson('/api/projects/shop/mysql/users/one')->assertOk()->assertJsonPath('data.user', 'shop_one');

        $this->assertSame([['deleteUser', 'shop_one']], $this->mysql->recorded());
        $this->assertFalse(MysqlUser::query()->where('user', 'shop_one')->exists());
    }

    public function test_a_renamed_user_keeps_its_row_under_the_new_name(): void
    {
        $this->shop->mysqlUsers()->create(['user' => 'shop_one']);

        $this->putJson('/api/projects/shop/mysql/users/one/rename', ['name' => 'two'])
            ->assertOk()
            ->assertJsonPath('data.user', 'shop_two');

        $this->assertSame([['renameUser', 'shop_one', 'shop_two']], $this->mysql->recorded());
        $this->assertSame(['shop_two'], MysqlUser::query()->pluck('user')->all());
    }

    public function test_a_password_change_reaches_sites_db_and_leaves_other_users_stored_password_alone(): void
    {
        $this->shop->mysqlUsers()->create(['user' => 'shop_one']);

        $this->putJson('/api/projects/shop/mysql/users/one/change-password', ['password' => 'another-secret'])
            ->assertOk()
            ->assertJsonPath('data.user', 'shop_one');

        $this->assertSame([['changeUserPassword', 'shop_one', 'another-secret']], $this->mysql->recorded());
        $this->assertArrayNotHasKey(AppDatabase::PASSWORD_DETAIL, $this->shop->fresh()->getDetails());
    }

    public function test_a_password_change_of_the_app_user_is_kept_for_the_next_deploy(): void
    {
        $this->shop->mysqlUsers()->create(['user' => AppDatabase::nameFor($this->shop)]);

        $this->putJson('/api/projects/shop/mysql/users/'.AppDatabase::SUFFIX.'/change-password', ['password' => 'another-secret'])
            ->assertOk();

        $this->assertSame('another-secret', $this->shop->fresh()->getDetails()[AppDatabase::PASSWORD_DETAIL] ?? null);
    }

    public function test_a_grant_is_shown_updated_and_revoked(): void
    {
        $this->shop->mysqlUsers()->create(['user' => 'shop_one']);
        $this->shop->mysqlDatabases()->create(['database' => 'shop_main']);
        $this->mysql->grants = ['shop_one' => ['shop_main' => 'SELECT, INSERT']];

        $this->getJson('/api/projects/shop/mysql/privileges/one/main')->assertOk()->assertExactJson(['data' => 'SELECT, INSERT']);
        $this->putJson('/api/projects/shop/mysql/privileges/one/main', ['privileges' => 'ALL PRIVILEGES'])
            ->assertOk()
            ->assertExactJson(['success' => true]);
        $this->deleteJson('/api/projects/shop/mysql/privileges/one/main')->assertOk()->assertExactJson(['success' => true]);

        $this->assertSame([
            ['updatePrivileges', 'shop_one', 'shop_main', 'ALL PRIVILEGES'],
            ['revokeIfExists', 'shop_one', 'shop_main'],
        ], $this->mysql->recorded());
    }

    public function test_a_database_the_user_has_no_grant_on_shows_an_empty_grant(): void
    {
        $this->shop->mysqlUsers()->create(['user' => 'shop_one']);
        $this->shop->mysqlDatabases()->create(['database' => 'shop_main']);

        $this->getJson('/api/projects/shop/mysql/privileges/one/main')->assertOk()->assertExactJson(['data' => '']);
    }
}
