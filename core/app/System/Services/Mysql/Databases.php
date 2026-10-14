<?php

namespace App\System\Services\Mysql;

use Illuminate\Database\Connection;
use PDO;

class Databases
{
    public function __construct(
        protected PDO $pdo,
    ) {
    }

    public function databaseExists(string $name): bool
    {
        $conn = new Connection($this->pdo);
        $query = "SHOW DATABASES LIKE ?";
        $result = $conn->select($query, [$name]);
        return !empty($result);
    }

    public function createDatabase(string $name): void
    {
        $query = "CREATE DATABASE `{$name}`";

        $this->pdo->exec($query);
    }

    public function deleteDatabase(string $name): void
    {
        $query = "DROP DATABASE `{$name}`";
        $this->pdo->exec($query);
    }

    /**
     * The base tables of a database. Views are not tables here: they are moved
     * by a copy, not by a metadata-only RENAME TABLE.
     *
     * @return list<string>
     */
    public function tables(string $name): array
    {
        $conn = new Connection($this->pdo);
        $rows = $conn->select(
            "SELECT table_name AS name FROM information_schema.tables "
                . "WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name",
            [$name]
        );

        return array_map(static fn (object $row): string => (string) $row->name, $rows);
    }

    /** A view, trigger, routine or event, none of which RENAME TABLE carries. */
    public function hasObjectsBeyondTables(string $name): bool
    {
        return $this->objectExists('information_schema.views', 'table_schema', $name)
            || $this->objectExists('information_schema.triggers', 'trigger_schema', $name)
            || $this->objectExists('information_schema.routines', 'routine_schema', $name)
            || $this->objectExists('information_schema.events', 'event_schema', $name);
    }

    /**
     * One atomic, metadata-only statement; the destination database must exist.
     *
     * @param list<string> $tables
     */
    public function renameTables(string $from, string $to, array $tables): void
    {
        $moves = [];
        foreach ($tables as $table) {
            $moves[] = self::quoteIdentifier($from) . '.' . self::quoteIdentifier($table)
                . ' TO ' . self::quoteIdentifier($to) . '.' . self::quoteIdentifier($table);
        }

        $this->pdo->exec('RENAME TABLE ' . implode(', ', $moves));
    }

    private function objectExists(string $table, string $schemaColumn, string $name): bool
    {
        $conn = new Connection($this->pdo);

        return $conn->selectOne("SELECT 1 FROM {$table} WHERE {$schemaColumn} = ? LIMIT 1", [$name]) !== null;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function getDatabaseSize(string $name): int
    {
        $conn = new Connection($this->pdo);
        $query = "SELECT SUM(data_length + index_length) AS size FROM information_schema.tables WHERE table_schema = ?";
        $result = $conn->select($query, [$name]);
        $firstKey = array_key_first($result);
        if (
            $firstKey
            && is_object($result[$firstKey])
            && (is_string($result[$firstKey]->size) || is_int($result[$firstKey]->size))
        ) {
            return (int)$result[$firstKey]->size;
        }
        return 0;
    }
}
