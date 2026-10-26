<?php

namespace App\System\Services\Mysql;

use Exception;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use PDO;

class Privileges
{
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
            [$db] = explode('.', $on, 2);
            $db = trim($db, '`');
            $privileges[$db] = $privs;
        }

        return $privileges;
    }
}
