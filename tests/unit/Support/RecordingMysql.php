<?php

namespace Tests\Support;

use App\System\Services\Mysql;
use App\System\Services\Mysql\Databases;
use App\System\Services\Mysql\Privileges;
use App\System\Services\Mysql\Users;
use ArrayObject;
use PDO;

/**
 * A sites-db that records every account, database and grant statement it is
 * asked for, and answers sizes, existence and grants from what a test set.
 */
class RecordingMysql extends Mysql
{
    /** @var ArrayObject<int, list<string>> */
    public ArrayObject $calls;

    /** @var array<string, array<string, string>> user => database => grant */
    public array $grants = [];

    /** @var array<string, int> */
    public array $sizes = [];

    /** @var list<string> */
    public array $existingUsers = [];

    private PDO $unused;

    public function __construct()
    {
        parent::__construct();
        $this->calls = new ArrayObject();
        $this->unused = new PDO('sqlite::memory:');
    }

    public function users(): Users
    {
        $calls = $this->calls;
        $existing = $this->existingUsers;

        return new class($this->unused, $calls, $existing) extends Users
        {
            /** @param list<string> $existing */
            public function __construct(PDO $pdo, private readonly ArrayObject $calls, private readonly array $existing)
            {
                parent::__construct($pdo, '%');
            }

            public function userExists(string $name): bool
            {
                return in_array($name, $this->existing, true);
            }

            public function createUser(string $name, string $password): void
            {
                $this->calls[] = ['createUser', $name, $password];
            }

            public function deleteUser(string $name): void
            {
                $this->calls[] = ['deleteUser', $name];
            }

            public function renameUser(string $name, string $newName): void
            {
                $this->calls[] = ['renameUser', $name, $newName];
            }

            public function changeUserPassword(string $name, string $password): void
            {
                $this->calls[] = ['changeUserPassword', $name, $password];
            }
        };
    }

    public function databases(): Databases
    {
        $calls = $this->calls;
        $sizes = $this->sizes;

        return new class($this->unused, $calls, $sizes) extends Databases
        {
            /** @param array<string, int> $sizes */
            public function __construct(PDO $pdo, private readonly ArrayObject $calls, private readonly array $sizes)
            {
                parent::__construct($pdo);
            }

            public function createDatabase(string $name): void
            {
                $this->calls[] = ['createDatabase', $name];
            }

            public function deleteDatabase(string $name): void
            {
                $this->calls[] = ['deleteDatabase', $name];
            }

            public function getDatabaseSize(string $name): int
            {
                return $this->sizes[$name] ?? 0;
            }
        };
    }

    public function privileges(): Privileges
    {
        $calls = $this->calls;
        $grants = $this->grants;

        return new class($this->unused, $calls, $grants) extends Privileges
        {
            /** @param array<string, array<string, string>> $grants */
            public function __construct(PDO $pdo, private readonly ArrayObject $calls, private readonly array $grants)
            {
                parent::__construct($pdo, '%');
            }

            public function revokeIfExists(string $user, string $database): void
            {
                $this->calls[] = ['revokeIfExists', $user, $database];
            }

            public function revokeAll(string $user): void
            {
                $this->calls[] = ['revokeAll', $user];
            }

            public function updatePrivileges(string $user, string $database, string $privileges): void
            {
                $this->calls[] = ['updatePrivileges', $user, $database, $privileges];
            }

            public function showPrivileges(string $user): array
            {
                return $this->grants[$user] ?? [];
            }
        };
    }

    /** @return list<list<string>> */
    public function recorded(): array
    {
        return $this->calls->getArrayCopy();
    }
}
