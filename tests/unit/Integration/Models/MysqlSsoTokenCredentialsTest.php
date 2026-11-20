<?php

namespace Tests\Integration\Models;

use App\Models\MysqlSsoToken;
use App\Models\MysqlSsoUser;
use App\System;
use App\System\Services\Mysql;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingMysql;
use Tests\TestCase;

/** The phpMyAdmin sign-in account a token hands out: made once, its password new every time, granted exactly the project's databases. */
class MysqlSsoTokenCredentialsTest extends TestCase
{
    use InMemoryDatabase;

    private RecordingMysql $mysql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['env.USERS_DB_HOST' => 'sites-db.example.test']);
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

    private function token(): MysqlSsoToken
    {
        $user = $this->makeUser('shop', ['mysql_prefix' => 'shop_']);
        $user->mysqlDatabases()->create(['database' => 'shop_main']);
        $user->mysqlDatabases()->create(['database' => 'shop_blog']);

        return MysqlSsoToken::query()->create(['user_id' => $user->id, 'token' => 'abc', 'expires_at' => now()->addMinute(), 'details' => []]);
    }

    public function test_the_first_sign_in_creates_the_account_and_grants_every_project_database(): void
    {
        $credentials = $this->token()->getCredentials();

        $this->assertSame('sso_shop', $credentials['username']);
        $this->assertSame(27, strlen($credentials['password']));
        $this->assertSame('sites-db.example.test', $credentials['host']);
        $this->assertSame([
            ['createUser', 'sso_shop', $credentials['password']],
            ['revokeAll', 'sso_shop'],
            ['updatePrivileges', 'sso_shop', 'shop_main', 'ALL PRIVILEGES'],
            ['updatePrivileges', 'sso_shop', 'shop_blog', 'ALL PRIVILEGES'],
        ], $this->mysql->recorded());
        $this->assertSame(['sso_shop'], MysqlSsoUser::query()->pluck('username')->all());
    }

    public function test_a_later_sign_in_sets_a_new_password_on_the_same_account(): void
    {
        $this->mysql->existingUsers = ['sso_shop'];
        $token = $this->token();

        $first = $token->getCredentials();
        $second = $token->getCredentials();

        $this->assertNotSame($first['password'], $second['password']);
        $this->assertSame(['changeUserPassword', 'sso_shop', $first['password']], $this->mysql->recorded()[0]);
        $this->assertSame(['changeUserPassword', 'sso_shop', $second['password']], $this->mysql->recorded()[4]);
        $this->assertSame(1, MysqlSsoUser::query()->count());
    }
}
