<?php

namespace Tests\Unit\System;

use App\System\Projects;
use App\System\Services\Mysql\Tables;
use PDO;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class PushTableProblemsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_selected_table_must_exist_in_a_database_the_source_owns(): void
    {
        $source = $this->sourceWithDatabase('alice_wp');

        $problems = Projects::tableProblems(
            $source,
            [['database' => 'alice_wp', 'name' => 'wp_absent']],
            $this->tablesDouble(['alice_wp' => ['wp_posts']]),
        );

        $this->assertSame('table_not_found', $problems[0]['code']);
        $this->assertSame('tables', $problems[0]['field']);
        $this->assertStringContainsString('alice_wp.wp_absent', $problems[0]['message']);
    }

    public function test_a_selected_database_the_source_does_not_own_is_refused(): void
    {
        $problems = Projects::tableProblems(
            $this->sourceWithDatabase('alice_wp'),
            [['database' => 'someone_else', 'name' => 'wp_posts']],
            $this->tablesDouble([]),
        );

        $this->assertSame('database_not_found', $problems[0]['code']);
        $this->assertSame('tables', $problems[0]['field']);
    }

    public function test_a_selection_that_exists_produces_no_problems(): void
    {
        $problems = Projects::tableProblems(
            $this->sourceWithDatabase('alice_wp'),
            [['database' => 'alice_wp', 'name' => 'wp_posts']],
            $this->tablesDouble(['alice_wp' => ['wp_posts']]),
        );

        $this->assertSame([], $problems);
    }

    private function sourceWithDatabase(string $database): \App\Models\User
    {
        $source = $this->makeUser('alice', ['mysql_prefix' => 'alice_']);
        $source->mysqlDatabases()->create(['database' => $database]);

        return $source->refresh();
    }

    /**
     * @param array<string, list<string>> $byDatabase
     */
    private function tablesDouble(array $byDatabase): Tables
    {
        return new class ($byDatabase) extends Tables {
            /**
             * @param array<string, list<string>> $byDatabase
             */
            public function __construct(private array $byDatabase)
            {
                parent::__construct(new PDO('sqlite::memory:'));
            }

            public function names(string $database): array
            {
                return $this->byDatabase[$database] ?? [];
            }
        };
    }
}
