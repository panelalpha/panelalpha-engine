<?php

namespace Tests\Unit\System\Project\Dind;

use App\System\Services\Mysql;
use App\System\Services\Mysql\Databases;
use App\System\Services\Mysql\Privileges;
use App\System\Services\Mysql\Users;
use PDO;

/**
 * The account MySQL server as a list of what was asked of it: databases and
 * users held in memory, every dump, restore and copy script recorded.
 */
class RecordingAppMysql extends Mysql
{
    /** @var list<string> */
    public array $databases = [];

    /** @var array<string, string> user => password */
    public array $users = [];

    /** @var list<array{0: string, 1: string, 2: string}> user, database, privileges */
    public array $grants = [];

    /** @var list<string> */
    public array $log = [];

    public ?string $failScriptContaining = null;

    public bool $failRestore = false;

    public function __construct()
    {
        parent::__construct(null);
    }

    public function dumpToGzip(string $name, string $dest, ?string $table = null): void
    {
        file_put_contents($dest, "dump of {$name}");
        $this->log[] = "dump {$name}";
    }

    public function restoreFromGzip(string $name, string $sqlGzPath): void
    {
        if ($this->failRestore) {
            throw new \RuntimeException('database restore failed');
        }
        $this->log[] = "restore {$name} from " . basename($sqlGzPath);
    }

    public function runSitesDbScript(string $script, string $failurePrefix): void
    {
        if ($this->failScriptContaining !== null && str_contains($script, $this->failScriptContaining)) {
            throw new \RuntimeException("{$failurePrefix}: copy exploded");
        }
        preg_match("/-uroot '([^']+)' \\|.*-uroot '([^']+)'$/", $script, $m);
        $this->log[] = 'copy ' . ($m[1] ?? '?') . ' to ' . ($m[2] ?? '?');
    }

    public function databases(): Databases
    {
        return new class ($this) extends Databases {
            public function __construct(private RecordingAppMysql $server)
            {
                parent::__construct(new PDO('sqlite::memory:'));
            }

            public function databaseExists(string $name): bool
            {
                return in_array($name, $this->server->databases, true);
            }

            public function createDatabase(string $name): void
            {
                if ($this->databaseExists($name)) {
                    throw new \RuntimeException("database {$name} exists");
                }
                $this->server->databases[] = $name;
                $this->server->log[] = "create {$name}";
            }

            public function deleteDatabase(string $name): void
            {
                $this->server->databases = array_values(array_diff($this->server->databases, [$name]));
                $this->server->log[] = "drop {$name}";
            }
        };
    }

    public function users(): Users
    {
        return new class ($this) extends Users {
            public function __construct(private RecordingAppMysql $server)
            {
                parent::__construct(new PDO('sqlite::memory:'), '%');
            }

            public function userExists(string $name): bool
            {
                return array_key_exists($name, $this->server->users);
            }

            public function createUser(string $name, string $password): void
            {
                $this->server->users[$name] = $password;
            }

            public function changeUserPassword(string $name, string $password): void
            {
                $this->server->users[$name] = $password;
            }
        };
    }

    public function privileges(): Privileges
    {
        return new class ($this) extends Privileges {
            public function __construct(private RecordingAppMysql $server)
            {
                parent::__construct(new PDO('sqlite::memory:'), '%');
            }

            public function showPrivileges(string $user): array
            {
                $shown = [];
                foreach ($this->server->grants as [$grantee, $database, $privileges]) {
                    if ($grantee === $user) {
                        $shown[$database] = $privileges;
                    }
                }

                return $shown;
            }

            public function updatePrivileges(string $user, string $database, string $privileges): void
            {
                $this->server->grants[] = [$user, $database, $privileges];
            }
        };
    }
}
