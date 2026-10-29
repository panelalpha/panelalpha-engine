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

    public function changeUserPassword(string $name, string $password): void
    {
        $conn = new Connection($this->pdo);
        $query = "ALTER USER ?@'{$this->host}' IDENTIFIED BY ?";
        $conn->statement($query, [$name, $password]);
        $conn->statement('FLUSH PRIVILEGES');
    }
}
