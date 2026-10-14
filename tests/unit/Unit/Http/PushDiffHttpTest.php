<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\System;
use App\System\Services\Mysql;
use App\System\Services\Mysql\Tables;
use PDO;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class PushDiffHttpTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
    }

    public function test_the_diff_lists_structure_and_data_changes(): void
    {
        [$from, $to] = $this->classicPair();
        $this->bindTables();

        $response = $this->getJson("/api/projects/{$from->username}/push/diff?target={$to->username}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.tables_structure_diff.0.database', 'alice_wp');
        $response->assertJsonPath('data.tables_structure_diff.0.name', 'wp_posts');
        $response->assertJsonPath('data.tables_data_diff.0.name', 'wp_posts');
    }

    public function test_a_dind_pair_is_refused(): void
    {
        $from = $this->makeUser('pushdiff-dind', ['template' => 'dind']);
        $to = $this->makeUser('pushdiff-dindstg', ['template' => 'dind']);
        $to->staging = $from->id;
        $to->save();

        $response = $this->getJson("/api/projects/{$from->username}/push/diff?target={$to->username}");

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.code', 'tables_not_applicable');
    }

    public function test_a_missing_target_is_a_validation_error(): void
    {
        [$from] = $this->classicPair();

        $response = $this->getJson("/api/projects/{$from->username}/push/diff");

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('target');
    }

    public function test_an_unknown_target_is_404(): void
    {
        [$from] = $this->classicPair();

        $response = $this->getJson("/api/projects/{$from->username}/push/diff?target=nope-nope");

        $response->assertStatus(404);
    }

    /**
     * @return array{0: \App\Models\User, 1: \App\Models\User}
     */
    private function classicPair(): array
    {
        $from = $this->makeUser('pushdiff-live', ['mysql_prefix' => 'alice_']);
        $from->mysqlDatabases()->create(['database' => 'alice_wp']);
        $to = $this->makeUser('pushdiff-stg', ['mysql_prefix' => 'bob_']);
        $to->staging = $from->id;
        $to->save();

        return [$from->refresh(), $to->refresh()];
    }

    private function bindTables(): void
    {
        $tables = new class extends Tables {
            public function __construct()
            {
                parent::__construct(new PDO('sqlite::memory:'));
            }

            public function names(string $database): array
            {
                return ['wp_posts'];
            }

            public function createStatement(string $database, string $table): ?string
            {
                return $database === 'alice_wp' ? 'CREATE A' : 'CREATE B';
            }

            public function checksum(string $database, string $table): ?string
            {
                return $database === 'alice_wp' ? '1' : '2';
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
