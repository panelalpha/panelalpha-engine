<?php

namespace Tests\Integration\WordpressMigration;

use App\Lib\Project\ProjectDeleter;
use App\Models\User;
use App\System;
use App\System\Project\PhpHosting\WordPress\Migration\AccountImportedSiteRemover;
use App\System\Services\Mysql;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** Taking an import back out drops its database and its user from the server and from the account's rows. */
class AccountImportedSiteRemoverDatabaseTest extends TestCase
{
    use InMemoryDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->user = $this->makeUser('wpimport');
        $this->user->mysqlDatabases()->create(['database' => 'wpimport_wp']);
        $this->user->mysqlDatabases()->create(['database' => 'wpimport_kept']);
        $this->user->mysqlUsers()->create(['user' => 'wpimport_wp']);
        $this->user->mysqlUsers()->create(['user' => 'wpimport_kept']);
    }

    public function test_the_database_and_its_user_are_dropped_from_the_server_and_the_account(): void
    {
        $server = $this->server(databases: ['wpimport_wp'], users: ['wpimport_wp']);

        (new AccountImportedSiteRemover)->dropDatabase($this->user, 'wpimport_wp', 'wpimport_wp');

        $this->assertSame(['delete database wpimport_wp', 'delete user wpimport_wp'], $server->calls);
        $this->assertSame(['wpimport_kept'], $this->user->mysqlDatabases()->pluck('database')->all());
        $this->assertSame(['wpimport_kept'], $this->user->mysqlUsers()->pluck('user')->all());
    }

    public function test_what_the_server_no_longer_holds_is_only_forgotten(): void
    {
        $server = $this->server();

        (new AccountImportedSiteRemover)->dropDatabase($this->user, 'wpimport_wp', 'wpimport_wp');

        $this->assertSame([], $server->calls);
        $this->assertSame(['wpimport_kept'], $this->user->mysqlDatabases()->pluck('database')->all());
        $this->assertSame(['wpimport_kept'], $this->user->mysqlUsers()->pluck('user')->all());
    }

    public function test_without_a_database_user_every_user_is_kept(): void
    {
        foreach ([null, ''] as $databaseUser) {
            $server = $this->server(databases: ['wpimport_wp'], users: ['wpimport_wp']);

            (new AccountImportedSiteRemover)->dropDatabase($this->user, 'wpimport_wp', $databaseUser);

            $this->assertSame(['delete database wpimport_wp'], $server->calls);
            $this->assertSame(['wpimport_kept', 'wpimport_wp'], $this->user->mysqlUsers()->orderBy('user')->pluck('user')->all());
        }
    }

    public function test_a_created_project_is_deleted_the_way_the_api_deletes_it(): void
    {
        $deleter = new class extends ProjectDeleter {
            /** @var list<string> */
            public array $deleted = [];

            public function delete(User $user): void
            {
                $this->deleted[] = $user->username;
            }
        };

        (new AccountImportedSiteRemover($deleter))->deleteProject($this->user);

        $this->assertSame(['wpimport'], $deleter->deleted);
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
