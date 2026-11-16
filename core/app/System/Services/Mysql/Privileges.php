<?php

namespace App\System\Services\Mysql;

use Exception;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use PDO;

class Privileges
{
    private const DATABASE_GRANT_TARGET = '/^`((?:[^`]|``)+)`\.\*$/';

    public function __construct(
        protected PDO $pdo,
        protected string $host,
    ) {}

    /**
     * REVOKE 'IF EXISTS'... doesn't work in mariadb
     */
    public function revokeIfExists(string $user, string $database): void
    {
        $showPrivileges = $this->showPrivileges($user);
        if (! empty($showPrivileges[$database])) {
            $this->pdo->exec("REVOKE ALL ON `{$database}`.* FROM `{$user}`@'{$this->host}'");
            $this->pdo->exec('FLUSH PRIVILEGES');
        }
    }

    public function revokeAll(string $user): void
    {
        $this->pdo->exec("REVOKE ALL ON *.* FROM `{$user}`@'{$this->host}'");
        $this->pdo->exec('FLUSH PRIVILEGES');
    }

    public function updatePrivileges(string $user, string $database, string $privileges): void
    {
        $conn = new Connection($this->pdo);
        $this->revokeIfExists($user, $database);
        $conn->statement("GRANT {$privileges} ON `{$database}`.* TO \"{$user}\"@'{$this->host}'");
        $conn->statement('FLUSH PRIVILEGES');
    }

    /**
     * @return array<string,string>
     *
     * @throws Exception
     */
    public function showPrivileges(string $user): array
    {
        $query = $this->pdo->prepare("SHOW GRANTS FOR `{$user}`@`{$this->host}`");
        try {
            $query->execute();
        } catch (Exception $e) {
            if (Str::contains($e->getMessage(), 'There is no such grant defined')) {
                return [];
            }
            throw $e;
        }

        return $this->grantedDatabases($query, $user);
    }

    /**
     * @return array<string,string>
     */
    private function grantedDatabases(\PDOStatement $query, string $user): array
    {
        $privileges = [];
        while ($res = $query->fetch()) {
            if (
                ! is_array($res)
                || ! array_key_exists(0, $res)
                || ! is_string($res[0])
            ) {
                continue;
            }
            $privs = Str::betweenFirst($res[0], 'GRANT ', ' ON ');
            if ($privs === 'USAGE') {
                continue;
            }
            $on = Str::betweenFirst($res[0], ' ON ', " TO `{$user}`@`{$this->host}`");
            $db = self::databaseOf($on);
            if ($db !== null) {
                $privileges[$db] = $privs;
            }
        }

        return $privileges;
    }

    /** The database a grant is on, or null when the grant is on a table, a routine or every database. */
    private static function databaseOf(string $on): ?string
    {
        if (preg_match(self::DATABASE_GRANT_TARGET, $on, $match) !== 1) {
            return null;
        }

        return str_replace('``', '`', $match[1]);
    }
}
