<?php

namespace Tests\Integration\Project;

use App\Models\MysqlDatabase;
use App\Models\MysqlSsoUser;
use App\Models\MysqlUser;
use App\Models\User;
use App\System;
use App\System\Services\Mysql;
use App\System\Services\Mysql\Databases;
use App\System\Services\Mysql\Users;
use ArrayObject;
use PDO;
use ReflectionMethod;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Deleting a project dropped its databases and their users but left the
 * `sso_<project>` account its phpMyAdmin sign-in creates, with the grant MySQL
 * keeps on the project's database.
 */
class ProjectDeleteDropsPhpMyAdminUserTest extends TestCase
{
    use InMemoryDatabase;

    private ArrayObject $dropped;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->dropped = new ArrayObject();
    }

    public function test_the_sign_in_account_is_dropped_with_the_project_users(): void
    {
        $user = $this->project('alice', signedIn: true);

        $this->deleteMysqlDatabasesAndUsers($user);

        $this->assertSame([
            ['database', 'alice_db'],
            ['user', 'alice_dbuser'],
            ['user', 'sso_alice'],
        ], $this->dropped->getArrayCopy());
    }

    public function test_a_project_with_only_a_sign_in_account_still_reaches_the_database_server(): void
    {
        $user = $this->makeUser('alice');
        MysqlSsoUser::create(['user_id' => $user->id, 'username' => 'sso_alice']);

        $this->deleteMysqlDatabasesAndUsers($user);

        $this->assertSame([['user', 'sso_alice']], $this->dropped->getArrayCopy());
    }

    public function test_a_project_that_never_signed_in_drops_its_own_users_only(): void
    {
        $this->project('bob', signedIn: true);

        $this->deleteMysqlDatabasesAndUsers($this->project('alice', signedIn: false));

        $this->assertSame([
            ['database', 'alice_db'],
            ['user', 'alice_dbuser'],
        ], $this->dropped->getArrayCopy());
        $this->assertSame(['sso_bob'], MysqlSsoUser::query()->pluck('username')->all());
    }

    public function test_the_sign_in_account_row_goes_with_the_project(): void
    {
        $user = $this->project('alice', signedIn: true);
        $this->project('bob', signedIn: true);

        $this->deleteOwnedRows($user);

        $this->assertSame(['sso_bob'], MysqlSsoUser::query()->pluck('username')->all());
    }

    private function project(string $username, bool $signedIn): User
    {
        $user = $this->makeUser($username);
        MysqlDatabase::create(['user_id' => $user->id, 'database' => "{$username}_db"]);
        MysqlUser::create(['user_id' => $user->id, 'user' => "{$username}_dbuser"]);
        if ($signedIn) {
            MysqlSsoUser::create(['user_id' => $user->id, 'username' => "sso_{$username}"]);
        }

        return $user;
    }

    private function deleteMysqlDatabasesAndUsers(User $user): void
    {
        $fresh = $user->refresh();
        $method = new ReflectionMethod(System\Project::class, 'deleteMysqlDatabasesAndUsers');
        $method->invoke($this->recordingSystem()->project($fresh), $fresh);
    }

    private function deleteOwnedRows(User $user): void
    {
        $method = new ReflectionMethod(System\Project::class, 'deleteOwnedRows');
        $method->invoke((new System)->project($user), $user);
    }

    /** Every database and every MySQL user the server is asked to remove, in the order it is asked. */
    private function recordingSystem(): System
    {
        return new class($this->dropped) extends System
        {
            public function __construct(private ArrayObject $dropped) {}

            public function mysql(): Mysql
            {
                return new class($this->dropped) extends Mysql
                {
                    public function __construct(private ArrayObject $dropped)
                    {
                        parent::__construct(null);
                    }

                    public function databases(): Databases
                    {
                        return new class($this->dropped) extends Databases
                        {
                            public function __construct(private ArrayObject $dropped)
                            {
                                parent::__construct(new PDO('sqlite::memory:'));
                            }

                            public function databaseExists(string $name): bool
                            {
                                return true;
                            }

                            public function deleteDatabase(string $name): void
                            {
                                $this->dropped->append(['database', $name]);
                            }
                        };
                    }

                    public function users(): Users
                    {
                        return new class($this->dropped) extends Users
                        {
                            public function __construct(private ArrayObject $dropped)
                            {
                                parent::__construct(new PDO('sqlite::memory:'), '%');
                            }

                            public function userExists(string $name): bool
                            {
                                return true;
                            }

                            public function deleteUser(string $name): void
                            {
                                $this->dropped->append(['user', $name]);
                            }
                        };
                    }
                };
            }
        };
    }
}
