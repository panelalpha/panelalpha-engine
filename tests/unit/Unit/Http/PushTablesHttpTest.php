<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Jobs\PushState;
use App\Models\Task;
use App\System;
use App\System\Services\Mysql;
use App\System\Services\Mysql\Tables;
use Illuminate\Support\Facades\Queue;
use PDO;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class PushTablesHttpTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();
    }

    public function test_tables_with_databases_disabled_is_refused(): void
    {
        [$from, $to] = $this->classicPair();
        $this->bindSourceTables(['alice_wp' => ['wp_posts']]);

        $response = $this->postJson("/api/projects/{$from->username}/push", [
            'target' => $to->username,
            'databases' => false,
            'tables' => [['database' => 'alice_wp', 'name' => 'wp_posts']],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'tables');
        $response->assertJsonPath('problems.0.code', 'tables_not_applicable');
        Queue::assertNothingPushed();
    }

    public function test_a_dind_source_refuses_table_selection(): void
    {
        $from = $this->makeUser('pushtbl-dind', ['template' => 'dind']);
        $to = $this->makeUser('pushtbl-dindstg', ['template' => 'dind']);
        $to->staging = $from->id;
        $to->save();

        $response = $this->postJson("/api/projects/{$from->username}/push", [
            'target' => $to->username,
            'tables' => [['database' => 'alice_wp', 'name' => 'wp_posts']],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.code', 'tables_not_applicable');
        Queue::assertNothingPushed();
    }

    public function test_a_table_missing_from_the_source_is_refused(): void
    {
        [$from, $to] = $this->classicPair();
        $this->bindSourceTables(['alice_wp' => ['wp_posts']]);

        $response = $this->postJson("/api/projects/{$from->username}/push", [
            'target' => $to->username,
            'tables' => [['database' => 'alice_wp', 'name' => 'wp_options']],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.code', 'table_not_found');
        Queue::assertNothingPushed();
    }

    public function test_a_table_name_outside_the_grammar_is_a_validation_error(): void
    {
        [$from, $to] = $this->classicPair();

        $response = $this->postJson("/api/projects/{$from->username}/push", [
            'target' => $to->username,
            'tables' => [['database' => 'alice_wp', 'name' => 'wp posts']],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('tables.0.name');
        Queue::assertNothingPushed();
    }

    public function test_a_valid_selection_reaches_the_push_job(): void
    {
        [$from, $to] = $this->classicPair();
        $this->bindSourceTables(['alice_wp' => ['wp_posts']]);

        $response = $this->postJson("/api/projects/{$from->username}/push", [
            'target' => $to->username,
            'tables' => [['database' => 'alice_wp', 'name' => 'wp_posts']],
        ]);

        $response->assertStatus(202);
        $tables = [['database' => 'alice_wp', 'name' => 'wp_posts']];
        $task = Task::query()->findOrFail($response->json('task_id'));
        $this->assertSame($tables, $task->details['tables']);
        Queue::assertPushed(PushState::class, static fn (PushState $job): bool => $job->tables === $tables);
    }

    /**
     * @return array{0: \App\Models\User, 1: \App\Models\User}
     */
    private function classicPair(): array
    {
        $from = $this->makeUser('pushtbl-live', ['mysql_prefix' => 'alice_']);
        $from->mysqlDatabases()->create(['database' => 'alice_wp']);
        $to = $this->makeUser('pushtbl-stg', ['mysql_prefix' => 'bob_']);
        $to->staging = $from->id;
        $to->save();

        return [$from->refresh(), $to->refresh()];
    }

    /**
     * @param array<string, list<string>> $byDatabase
     */
    private function bindSourceTables(array $byDatabase): void
    {
        $tables = new class ($byDatabase) extends Tables {
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

        $mysql = new class ($tables) extends Mysql {
            public function __construct(private Tables $tables)
            {
                parent::__construct(null);
            }

            public function tables(): Tables
            {
                return $this->tables;
            }
        };

        $this->app->instance(System::class, new class ($mysql) extends System {
            public function __construct(private Mysql $mysql)
            {
            }

            public function mysql(): Mysql
            {
                return $this->mysql;
            }
        });
    }
}
