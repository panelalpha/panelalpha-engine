<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Models\MysqlDatabase;
use App\Models\MysqlUser;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The MySQL database, user and privilege endpoints answer from the engine's own records, and refuse a name, a limit
 * or an unknown account before they connect to sites-db: none of these requests opens a MySQL connection.
 */
class MysqlAccountRefusalHttpTest extends TestCase
{
    use InMemoryDatabase;

    private User $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->shop = $this->makeUser('shop', ['mysql_prefix' => 'shop_']);
    }

    public function test_a_project_without_databases_or_users_lists_none(): void
    {
        $this->getJson('/api/projects/shop/mysql/databases')->assertOk()->assertExactJson(['data' => []]);
        $this->getJson('/api/projects/shop/mysql/users')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_a_database_is_shown_with_or_without_its_prefix(): void
    {
        $this->shop->mysqlDatabases()->create(['database' => 'shop_main']);

        $this->getJson('/api/projects/shop/mysql/databases/main')->assertOk()->assertJsonPath('data.database', 'shop_main');
        $this->getJson('/api/projects/shop/mysql/databases/shop_main')->assertOk()->assertJsonPath('data.database', 'shop_main');
        $this->getJson('/api/projects/shop/mysql/databases/other')
            ->assertNotFound()
            ->assertExactJson(['message' => 'MySQL database not found']);
    }

    public function test_a_database_over_the_limit_is_refused(): void
    {
        $this->shop->setMysqlDatabasesLimit(1);
        $this->shop->save();
        $this->shop->mysqlDatabases()->create(['database' => 'shop_main']);

        $this->postJson('/api/projects/shop/mysql/databases', ['name' => 'second'])
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'MySQL databases limit of 1 reached.', 'error_type' => 'mysql_databases_limit_reached']);
    }

    public function test_an_unusable_database_name_is_refused(): void
    {
        $plain = $this->makeUser('plain');
        $this->shop->mysqlDatabases()->create(['database' => 'shop_main']);

        $this->postJson('/api/projects/plain/mysql/databases', ['name' => 'mysql'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'This database name is reserved by MySQL and cannot be used.');

        $this->postJson('/api/projects/shop/mysql/databases', ['name' => str_repeat('d', 60)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Database name with prefix exceeds 64 characters (MySQL limit). Current length: 65 characters.');

        $this->postJson('/api/projects/shop/mysql/databases', ['name' => 'main'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Database with given name already exists.');

        $this->assertSame(['shop_main'], MysqlDatabase::query()->pluck('database')->all());
        $this->assertSame(0, $plain->mysqlDatabases()->count());
    }

    public function test_an_unknown_project_or_database_is_not_found(): void
    {
        $this->postJson('/api/projects/nobody/mysql/databases', ['name' => 'main'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Project not found']);
        $this->deleteJson('/api/projects/shop/mysql/databases/missing')
            ->assertNotFound()
            ->assertExactJson(['message' => 'MySQL database not found']);
    }

    public function test_a_user_is_shown_with_or_without_its_prefix(): void
    {
        MysqlUser::create(['user_id' => $this->shop->id, 'user' => 'shop_app']);

        $this->getJson('/api/projects/shop/mysql/users/app')->assertOk()->assertJsonPath('data.user', 'shop_app');
        $this->getJson('/api/projects/shop/mysql/users/shop_app')->assertOk()->assertJsonPath('data.user', 'shop_app');
        $this->getJson('/api/projects/shop/mysql/users/ghost')->assertNotFound();
    }

    public function test_an_unusable_user_name_is_refused_on_create(): void
    {
        $this->makeUser('plain');
        MysqlUser::create(['user_id' => $this->shop->id, 'user' => 'shop_app']);

        $this->postJson('/api/projects/plain/mysql/users', ['name' => 'root', 'password' => 'secret-pass'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'This username is reserved by MySQL and cannot be used.');

        $this->postJson('/api/projects/shop/mysql/users', ['name' => str_repeat('u', 30), 'password' => 'secret-pass'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'MySQL user name with prefix exceeds 32 characters (MySQL limit). Current length: 35 characters.');

        $this->postJson('/api/projects/shop/mysql/users', ['name' => 'app', 'password' => 'secret-pass'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'User with given name already exists.');

        $this->assertSame(['shop_app'], MysqlUser::query()->pluck('user')->all());
    }

    public function test_a_rename_onto_a_taken_or_reserved_name_is_refused(): void
    {
        MysqlUser::create(['user_id' => $this->shop->id, 'user' => 'shop_app']);
        MysqlUser::create(['user_id' => $this->shop->id, 'user' => 'shop_other']);

        $this->putJson('/api/projects/shop/mysql/users/app/rename', ['name' => 'other'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'User with given name already exists.');

        $this->putJson('/api/projects/shop/mysql/users/ghost/rename', ['name' => 'fresh'])->assertNotFound();

        $this->assertSame(['shop_app', 'shop_other'], MysqlUser::query()->orderBy('user')->pluck('user')->all());
    }

    public function test_a_password_change_validates_before_it_finds_no_user(): void
    {
        $this->putJson('/api/projects/shop/mysql/users/app/change-password', ['password' => 'short'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'Password must be at least 8 characters long.');

        $this->putJson('/api/projects/shop/mysql/users/app/change-password', ['password' => 'long-enough-pass'])->assertNotFound();
        $this->deleteJson('/api/projects/shop/mysql/users/app')->assertNotFound();
    }

    public function test_privileges_name_an_existing_user_and_database(): void
    {
        MysqlUser::create(['user_id' => $this->shop->id, 'user' => 'shop_app']);

        foreach (['get', 'delete'] as $method) {
            $this->json($method, '/api/projects/shop/mysql/privileges/ghost/main')->assertNotFound();
            $this->json($method, '/api/projects/shop/mysql/privileges/app/main')->assertNotFound();
        }
        $this->putJson('/api/projects/shop/mysql/privileges/ghost/main', ['privileges' => 'SELECT,INSERT'])->assertNotFound();
        $this->putJson('/api/projects/shop/mysql/privileges/app/main', ['privileges' => 'ALL PRIVILEGES'])->assertNotFound();
    }

    public function test_only_known_privileges_are_accepted(): void
    {
        MysqlUser::create(['user_id' => $this->shop->id, 'user' => 'shop_app']);
        $this->shop->mysqlDatabases()->create(['database' => 'shop_main']);

        $this->putJson('/api/projects/shop/mysql/privileges/app/main', ['privileges' => 'SELECT,GRANT OPTION'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.privileges.0', 'Invalid value.');

        $this->putJson('/api/projects/shop/mysql/privileges/app/main', ['privileges' => ['SELECT']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.privileges.0', 'Invalid value.');
    }
}
