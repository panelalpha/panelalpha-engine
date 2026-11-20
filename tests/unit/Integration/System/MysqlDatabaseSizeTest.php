<?php

namespace Tests\Integration\System;

use App\System\Services\Mysql\Databases;
use PDO;
use Tests\TestCase;

/**
 * A database's size, as an export's free-space preflight and the database
 * listing report it, read from information_schema.
 */
class MysqlDatabaseSizeTest extends TestCase
{
    public function test_the_size_is_the_sum_of_the_data_and_the_indexes_of_its_tables(): void
    {
        $databases = new Databases($this->schema([
            ['shop_wp', 800, 200],
            ['shop_wp', 4000, 1000],
            ['other_db', 99999, 1],
        ]));

        $this->assertSame(6000, $databases->getDatabaseSize('shop_wp'));
    }

    public function test_a_database_without_tables_has_no_size(): void
    {
        $databases = new Databases($this->schema([['other_db', 10, 10]]));

        $this->assertSame(0, $databases->getDatabaseSize('shop_wp'));
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int}>  $tables
     */
    private function schema(array $tables): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
        $pdo->exec('CREATE TABLE information_schema.tables (table_schema TEXT, data_length INTEGER, index_length INTEGER)');
        $insert = $pdo->prepare('INSERT INTO information_schema.tables VALUES (?, ?, ?)');
        foreach ($tables as $table) {
            $insert->execute($table);
        }

        return $pdo;
    }
}
