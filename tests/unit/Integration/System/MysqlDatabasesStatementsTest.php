<?php

namespace Tests\Integration\System;

use App\System\Services\Mysql\Databases;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The database-level statements: existence, create and drop, the base tables a rename moves, and the objects it cannot.
 */
class MysqlDatabasesStatementsTest extends TestCase
{
    public function test_a_database_exists_when_the_server_lists_it(): void
    {
        $databases = new Databases($this->server(schemata: ['shop_wp', 'blog_wp']));

        $this->assertTrue($databases->databaseExists('shop_wp'));
        $this->assertFalse($databases->databaseExists('shop'));
    }

    public function test_create_and_drop_quote_the_name_as_an_identifier(): void
    {
        $pdo = $this->server();
        $databases = new Databases($pdo);

        $databases->createDatabase('shop_wp');
        $databases->deleteDatabase('shop_wp');

        $this->assertSame(['CREATE DATABASE `shop_wp`', 'DROP DATABASE `shop_wp`'], $this->executed($pdo));
    }

    public function test_only_the_base_tables_of_the_database_are_listed_in_order(): void
    {
        $databases = new Databases($this->server(tables: [
            ['shop_wp', 'wp_users', 'BASE TABLE'],
            ['shop_wp', 'wp_options', 'BASE TABLE'],
            ['shop_wp', 'wp_report', 'VIEW'],
            ['blog_wp', 'wp_posts', 'BASE TABLE'],
        ]));

        $this->assertSame(['wp_options', 'wp_users'], $databases->tables('shop_wp'));
        $this->assertSame([], $databases->tables('empty_wp'));
    }

    public function test_a_database_with_only_tables_has_nothing_a_rename_would_leave_behind(): void
    {
        $databases = new Databases($this->server(objects: ['views' => 'blog_wp']));

        $this->assertFalse($databases->hasObjectsBeyondTables('shop_wp'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function objectKinds(): iterable
    {
        yield 'a view' => ['views'];
        yield 'a trigger' => ['triggers'];
        yield 'a routine' => ['routines'];
        yield 'an event' => ['events'];
    }

    #[DataProvider('objectKinds')]
    public function test_any_object_beyond_tables_is_found(string $kind): void
    {
        $databases = new Databases($this->server(objects: [$kind => 'shop_wp']));

        $this->assertTrue($databases->hasObjectsBeyondTables('shop_wp'));
    }

    public function test_tables_are_renamed_in_one_statement_with_backticks_doubled(): void
    {
        $pdo = $this->server();

        (new Databases($pdo))->renameTables('shop_wp', 'shop`new', ['wp_posts', 'wp_`odd']);

        $this->assertSame(
            ['RENAME TABLE `shop_wp`.`wp_posts` TO `shop``new`.`wp_posts`, `shop_wp`.`wp_``odd` TO `shop``new`.`wp_``odd`'],
            $this->executed($pdo),
        );
    }

    /**
     * A SQLite stand-in for the server's information_schema; `SHOW DATABASES LIKE` reads $schemata, exec is recorded.
     *
     * @param  list<string>  $schemata
     * @param  list<array{0: string, 1: string, 2: string}>  $tables
     * @param  array<string, string>  $objects  information_schema table => schema holding one such object
     */
    private function server(array $schemata = [], array $tables = [], array $objects = []): PDO
    {
        $pdo = new class extends PDO {
            /** @var list<string> */
            public array $executed = [];

            public function __construct()
            {
                parent::__construct('sqlite::memory:');
                $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                parent::exec('CREATE TABLE schemata (name TEXT)');
                parent::exec("ATTACH DATABASE ':memory:' AS information_schema");
                parent::exec('CREATE TABLE information_schema.tables (table_schema TEXT, table_name TEXT, table_type TEXT)');
                parent::exec('CREATE TABLE information_schema.views (table_schema TEXT)');
                parent::exec('CREATE TABLE information_schema.triggers (trigger_schema TEXT)');
                parent::exec('CREATE TABLE information_schema.routines (routine_schema TEXT)');
                parent::exec('CREATE TABLE information_schema.events (event_schema TEXT)');
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                if ($query === 'SHOW DATABASES LIKE ?') {
                    $query = 'SELECT name FROM schemata WHERE name LIKE ?';
                }

                return parent::prepare($query, $options);
            }

            public function exec(string $statement): int|false
            {
                $this->executed[] = $statement;

                return 0;
            }

            /** @param  list<string>  $values */
            public function seed(string $table, array $values): void
            {
                $placeholders = implode(', ', array_fill(0, count($values), '?'));
                parent::prepare("INSERT INTO {$table} VALUES ({$placeholders})")->execute($values);
            }
        };

        foreach ($schemata as $name) {
            $pdo->seed('schemata', [$name]);
        }
        foreach ($tables as [$schema, $name, $type]) {
            $pdo->seed('information_schema.tables', [$schema, $name, $type]);
        }
        foreach ($objects as $kind => $schema) {
            $pdo->seed('information_schema.'.$kind, [$schema]);
        }

        return $pdo;
    }

    /** @return list<string> */
    private function executed(PDO $pdo): array
    {
        return $pdo->executed;
    }
}
