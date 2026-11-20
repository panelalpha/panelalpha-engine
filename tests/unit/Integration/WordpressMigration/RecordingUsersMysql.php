<?php

namespace Tests\Integration\WordpressMigration;

use App\System\Services\Mysql;
use App\System\Services\Mysql\Databases;
use App\System\Services\Mysql\Privileges;
use App\System\Services\Mysql\Users;
use PDO;

/** The users' MySQL server as a list of what was asked of it, holding the databases and users given. */
final class RecordingUsersMysql extends Mysql
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param  list<string>  $databases
     * @param  list<string>  $users
     */
    public function __construct(public array $databases = [], public array $users = [])
    {
        parent::__construct(null);
    }

    public function databases(): Databases
    {
        return new class ($this) extends Databases {
            public function __construct(private RecordingUsersMysql $server)
            {
                parent::__construct(new PDO('sqlite::memory:'));
            }

            public function databaseExists(string $name): bool
            {
                return in_array($name, $this->server->databases, true);
            }

            public function createDatabase(string $name): void
            {
                $this->server->calls[] = "create database {$name}";
            }

            public function deleteDatabase(string $name): void
            {
                $this->server->calls[] = "delete database {$name}";
            }
        };
    }

    public function users(): Users
    {
        return new class ($this) extends Users {
            public function __construct(private RecordingUsersMysql $server)
            {
                parent::__construct(new PDO('sqlite::memory:'), '%');
            }

            public function userExists(string $name): bool
            {
                return in_array($name, $this->server->users, true);
            }

            public function createUser(string $name, string $password): void
            {
                $this->server->calls[] = "create user {$name} with {$password}";
            }

            public function deleteUser(string $name): void
            {
                $this->server->calls[] = "delete user {$name}";
            }

            public function changeUserPassword(string $name, string $password): void
            {
                $this->server->calls[] = "change password of {$name} to {$password}";
            }
        };
    }

    public function privileges(): Privileges
    {
        return new class ($this) extends Privileges {
            public function __construct(private RecordingUsersMysql $server)
            {
                parent::__construct(new PDO('sqlite::memory:'), '%');
            }

            public function updatePrivileges(string $user, string $database, string $privileges): void
            {
                $this->server->calls[] = "grant {$privileges} on {$database} to {$user}";
            }
        };
    }
}
