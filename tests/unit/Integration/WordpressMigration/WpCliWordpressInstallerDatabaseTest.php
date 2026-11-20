<?php

namespace Tests\Integration\WordpressMigration;

use App\Models\MysqlDatabase;
use App\Models\MysqlUser;
use App\Models\User;
use App\System;
use App\System\Project\PhpHosting\WordPress\Migration\WpCliWordpressInstaller;
use App\System\Services\Mysql;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** The destination database is made once, its user takes the run's password, and both are the account's. */
class WpCliWordpressInstallerDatabaseTest extends TestCase
{
    use InMemoryDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->user = $this->makeUser('wpdest');
    }

    public function test_a_new_database_and_user_are_created_granted_and_recorded(): void
    {
        $server = $this->server();

        (new WpCliWordpressInstaller)->ensureDatabase($this->user, 'wpdest_wp', 'wpdest_wp', 'run-password');

        $this->assertSame([
            'create database wpdest_wp',
            'create user wpdest_wp with run-password',
            'grant ALL PRIVILEGES on wpdest_wp to wpdest_wp',
        ], $server->calls);
        $this->assertSame(['wpdest_wp'], $this->user->mysqlDatabases()->pluck('database')->all());
        $this->assertSame(['wpdest_wp'], $this->user->mysqlUsers()->pluck('user')->all());
    }

    public function test_an_existing_database_is_kept_and_its_user_takes_the_new_password(): void
    {
        $this->user->mysqlDatabases()->create(['database' => 'wpdest_wp']);
        $this->user->mysqlUsers()->create(['user' => 'wpdest_wp']);
        $server = $this->server(databases: ['wpdest_wp'], users: ['wpdest_wp']);

        (new WpCliWordpressInstaller)->ensureDatabase($this->user, 'wpdest_wp', 'wpdest_wp', 'second-password');

        $this->assertSame([
            'change password of wpdest_wp to second-password',
            'grant ALL PRIVILEGES on wpdest_wp to wpdest_wp',
        ], $server->calls);
        $this->assertSame(1, MysqlDatabase::query()->where('database', 'wpdest_wp')->count());
        $this->assertSame(1, MysqlUser::query()->where('user', 'wpdest_wp')->count());
    }

    /**
     * @param  list<string>  $databases
     * @param  list<string>  $users
     */
    private function server(array $databases = [], array $users = []): RecordingUsersMysql
    {
        $server = new RecordingUsersMysql($databases, $users);
        $this->app->instance(System::class, new class ($server) extends System {
            public function __construct(private Mysql $server)
            {
            }

            public function mysql(): Mysql
            {
                return $this->server;
            }
        });

        return $server;
    }
}
