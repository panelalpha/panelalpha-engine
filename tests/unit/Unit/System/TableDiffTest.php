<?php

namespace Tests\Unit\System;

use App\Models\User;
use App\System\Services\Mysql;
use App\System\Services\Mysql\TableDiff;
use App\System\Services\Mysql\Tables;
use PDO;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class TableDiffTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_changed_row_is_a_data_diff_and_a_changed_definition_is_a_structure_diff(): void
    {
        $diff = (new TableDiff($this->mysqlReturning($this->tablesDouble())))->between(
            $this->userWithDatabase('alice', 'alice_wp'),
            $this->userWithDatabase('bob', 'bob_wp'),
        );

        $this->assertSame(
            [['database' => 'alice_wp', 'name' => 'wp_posts']],
            $diff['tables_structure_diff']
        );
        $this->assertSame(
            [['database' => 'alice_wp', 'name' => 'wp_posts']],
            $diff['tables_data_diff']
        );
    }

    public function test_a_table_the_target_does_not_hold_is_a_structure_diff_only(): void
    {
        $diff = (new TableDiff($this->mysqlReturning($this->tablesDouble(targetNames: []))))->between(
            $this->userWithDatabase('alice', 'alice_wp'),
            $this->userWithDatabase('bob', 'bob_wp'),
        );

        $this->assertSame(
            [['database' => 'alice_wp', 'name' => 'wp_posts']],
            $diff['tables_structure_diff']
        );
        $this->assertSame([], $diff['tables_data_diff']);
    }

    public function test_identical_tables_are_not_reported(): void
    {
        $diff = (new TableDiff($this->mysqlReturning($this->tablesDouble(identical: true))))->between(
            $this->userWithDatabase('alice', 'alice_wp'),
            $this->userWithDatabase('bob', 'bob_wp'),
        );

        $this->assertSame([], $diff['tables_structure_diff']);
        $this->assertSame([], $diff['tables_data_diff']);
    }

    private function userWithDatabase(string $name, string $database): User
    {
        $user = $this->makeUser($name, ['mysql_prefix' => $name . '_']);
        $user->mysqlDatabases()->create(['database' => $database]);

        return $user->refresh();
    }

    private function mysqlReturning(Tables $tables): Mysql
    {
        return new class ($tables) extends Mysql {
            public function __construct(private Tables $tables)
            {
                parent::__construct(null);
            }

            public function tables(): Tables
            {
                return $this->tables;
            }
        };
    }

    /**
     * A source and a target table named `wp_posts`: the source definition is
     * always A, the target's is B unless `$identical`, and the source checksum
     * is 1 while the target's is 2.
     *
     * @param list<string>|null $targetNames
     */
    private function tablesDouble(?array $targetNames = null, bool $identical = false): Tables
    {
        $targetNames ??= ['wp_posts'];

        return new class ($targetNames, $identical) extends Tables {
            /**
             * @param list<string> $targetNames
             */
            public function __construct(private array $targetNames, private bool $identical)
            {
                parent::__construct(new PDO('sqlite::memory:'));
            }

            public function names(string $database): array
            {
                return $database === 'bob_wp' ? $this->targetNames : ['wp_posts'];
            }

            public function createStatement(string $database, string $table): ?string
            {
                return $database === 'alice_wp' || $this->identical ? 'CREATE A' : 'CREATE B';
            }

            public function checksum(string $database, string $table): ?string
            {
                return $database === 'alice_wp' || $this->identical ? '1' : '2';
            }
        };
    }
}
