<?php

namespace Tests\Integration\System;

use App\System\Services\Mysql\Tables;
use PDO;
use PDOStatement;
use Tests\TestCase;

/**
 * The table reads a selective push diffs on: the base tables, each one's CREATE statement and its checksum.
 */
class MysqlTablesStatementsTest extends TestCase
{
    public function test_the_base_tables_of_one_database_are_named_in_order(): void
    {
        $tables = new Tables($this->server(tables: [
            ['shop_wp', 'wp_users', 'BASE TABLE'],
            ['shop_wp', 'wp_options', 'BASE TABLE'],
            ['shop_wp', 'wp_report', 'VIEW'],
            ['blog_wp', 'wp_posts', 'BASE TABLE'],
        ]));

        $this->assertSame(['wp_options', 'wp_users'], $tables->names('shop_wp'));
        $this->assertTrue($tables->exists('shop_wp', 'wp_users'));
        $this->assertFalse($tables->exists('shop_wp', 'wp_report'));
        $this->assertFalse($tables->exists('shop_wp', 'wp_posts'));
    }

    public function test_the_create_statement_is_read_for_the_quoted_table(): void
    {
        $pdo = $this->server(answers: [
            'SHOW CREATE TABLE `shop_wp`.`wp_``odd`' => "SELECT 'wp_`odd' AS \"Table\", 'CREATE TABLE `wp_``odd` (id int)' AS \"Create Table\"",
        ]);

        $this->assertSame('CREATE TABLE `wp_``odd` (id int)', (new Tables($pdo))->createStatement('shop_wp', 'wp_`odd'));
    }

    public function test_a_table_without_a_create_statement_has_none(): void
    {
        $pdo = $this->server(answers: [
            'SHOW CREATE TABLE `shop_wp`.`gone`' => 'SELECT 1 WHERE 0',
            'SHOW CREATE TABLE `shop_wp`.`odd`' => "SELECT 'odd' AS \"Table\"",
        ]);
        $tables = new Tables($pdo);

        $this->assertNull($tables->createStatement('shop_wp', 'gone'));
        $this->assertNull($tables->createStatement('shop_wp', 'odd'));
    }

    public function test_the_checksum_is_read_as_text(): void
    {
        $pdo = $this->server(answers: [
            'CHECKSUM TABLE `shop_wp`.`wp_posts`' => "SELECT 'shop_wp.wp_posts' AS \"Table\", 3274189765 AS \"Checksum\"",
        ]);

        $this->assertSame('3274189765', (new Tables($pdo))->checksum('shop_wp', 'wp_posts'));
    }

    public function test_a_missing_or_empty_checksum_is_none(): void
    {
        $pdo = $this->server(answers: [
            'CHECKSUM TABLE `shop_wp`.`none`' => 'SELECT 1 WHERE 0',
            'CHECKSUM TABLE `shop_wp`.`null`' => "SELECT 'shop_wp.null' AS \"Table\", NULL AS \"Checksum\"",
            'CHECKSUM TABLE `shop_wp`.`blank`' => "SELECT 'shop_wp.blank' AS \"Table\", '' AS \"Checksum\"",
        ]);
        $tables = new Tables($pdo);

        $this->assertNull($tables->checksum('shop_wp', 'none'));
        $this->assertNull($tables->checksum('shop_wp', 'null'));
        $this->assertNull($tables->checksum('shop_wp', 'blank'));
    }

    public function test_an_identifier_is_quoted_with_its_backticks_doubled(): void
    {
        $this->assertSame('`wp_posts`', Tables::quote('wp_posts'));
        $this->assertSame('`a``b`', Tables::quote('a`b'));
    }

    /**
     * A SQLite stand-in: information_schema.TABLES holds $tables, and each query in $answers runs its SQLite replacement.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $tables
     * @param  array<string, string>  $answers
     */
    private function server(array $tables = [], array $answers = []): PDO
    {
        $pdo = new class ($answers) extends PDO {
            /** @param  array<string, string>  $answers */
            public function __construct(private array $answers)
            {
                parent::__construct('sqlite::memory:');
                $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->exec("ATTACH DATABASE ':memory:' AS information_schema");
                $this->exec('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, TABLE_TYPE TEXT)');
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                return parent::prepare($this->answers[$query] ?? $query, $options);
            }
        };

        $insert = $pdo->prepare('INSERT INTO information_schema.TABLES VALUES (?, ?, ?)');
        foreach ($tables as $table) {
            $insert->execute($table);
        }

        return $pdo;
    }
}
