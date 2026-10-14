<?php

namespace App\System\Services\Mysql;

use Illuminate\Database\Connection;
use PDO;

/**
 * Table-level reads a selective push and its diff need: the tables of one
 * database, and the two statements that say whether a table differs.
 */
class Tables
{
    public function __construct(
        protected PDO $pdo,
    ) {
    }

    /**
     * @return list<string>
     */
    public function names(string $database): array
    {
        $conn = new Connection($this->pdo);
        $rows = $conn->select(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME',
            [$database, 'BASE TABLE']
        );

        $names = [];
        foreach ($rows as $row) {
            $names[] = (string) $row->name;
        }

        return $names;
    }

    public function exists(string $database, string $table): bool
    {
        return in_array($table, $this->names($database), true);
    }

    public function createStatement(string $database, string $table): ?string
    {
        $rows = (new Connection($this->pdo))->select(
            'SHOW CREATE TABLE ' . self::quote($database) . '.' . self::quote($table)
        );
        if ($rows === []) {
            return null;
        }

        $row = (array) $rows[0];

        return isset($row['Create Table']) ? (string) $row['Create Table'] : null;
    }

    public function checksum(string $database, string $table): ?string
    {
        $rows = (new Connection($this->pdo))->select(
            'CHECKSUM TABLE ' . self::quote($database) . '.' . self::quote($table)
        );
        if ($rows === []) {
            return null;
        }

        $row = (array) $rows[0];
        $checksum = $row['Checksum'] ?? null;

        return is_scalar($checksum) && (string) $checksum !== '' ? (string) $checksum : null;
    }

    /** A MySQL identifier, quoted for SQL text: any backtick in it is doubled. */
    public static function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
