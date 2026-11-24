<?php

namespace App\System\Services\Mysql;

use Illuminate\Database\Connection;
use PDO;

class Users
{
    public function __construct(
        protected PDO $pdo,
        protected string $host,
    ) {}

    public function userExists(string $name): bool
    {
        $conn = new Connection($this->pdo);
        $query = 'SELECT 1 FROM mysql.user WHERE user = ?';
        $result = $conn->select($query, [$name]);

        return ! empty($result);
    }

    /** Whether $name exists and signs in first with mysql_native_password, the method a copy's import can add to. */
    public function signsInNatively(string $name): bool
    {
        $conn = new Connection($this->pdo);
        $query = "SELECT JSON_VALUE(Priv, '$.plugin') AS plugin FROM mysql.global_priv WHERE User = ? AND Host = ?";
        $row = $conn->selectOne($query, [$name, $this->host]);

        return is_object($row) && ($row->plugin ?? null) === 'mysql_native_password';
    }

    public function createUser(string $name, string $password): void
    {
        $conn = new Connection($this->pdo);
        $query = "CREATE USER ?@'{$this->host}' IDENTIFIED BY ?";
        $conn->statement($query, [$name, $password]);
    }

    public function deleteUser(string $name): void
    {
        $conn = new Connection($this->pdo);
        $query = "DROP USER ?@'{$this->host}'";
        $conn->statement($query, [$name]);
    }

    public function renameUser(string $name, string $newName): void
    {
        $conn = new Connection($this->pdo);
        $query = "RENAME USER ?@'{$this->host}' TO ?@'{$this->host}'";
        $conn->statement($query, [$name, $newName]);
    }

    /** Closes every open connection of the user; one with none is no error. */
    public function killSessions(string $name): void
    {
        $this->pdo->exec('KILL CONNECTION USER '.$this->pdo->quote($name)."@'{$this->host}'");
    }

    public function changeUserPassword(string $name, string $password): void
    {
        $conn = new Connection($this->pdo);
        $query = "ALTER USER ?@'{$this->host}' IDENTIFIED BY ?";
        $conn->statement($query, [$name, $password]);
        $conn->statement('FLUSH PRIVILEGES');
    }
}
