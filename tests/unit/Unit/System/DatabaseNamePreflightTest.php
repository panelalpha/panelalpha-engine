<?php

namespace Tests\Unit\System;

use App\Http\Middleware\Authenticate;
use App\Models\User;
use App\System\Projects;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class DatabaseNamePreflightTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware([Authenticate::class]);
    }

    public function test_a_mapped_database_name_over_64_is_refused(): void
    {
        $source = $this->sourceWithDatabase('alice_' . str_repeat('d', 60));

        $problems = Projects::createProblems($source, 'bobstaging_');

        $this->assertSame('database_name_too_long', $problems[0]['code']);
        $this->assertSame('new_username', $problems[0]['field']);
        $this->assertStringContainsString('alice_', $problems[0]['message']);
    }

    public function test_a_mapped_user_name_over_32_is_refused(): void
    {
        $source = $this->sourceWithUser('alice_' . str_repeat('u', 30));

        $problems = Projects::createProblems($source, 'bobstaging_');

        $this->assertSame('database_user_name_too_long', $problems[0]['code']);
        $this->assertSame('new_username', $problems[0]['field']);
    }

    public function test_a_source_database_outside_the_copy_grammar_is_refused(): void
    {
        $source = $this->sourceWithDatabase('alice_wp$x');

        $problems = Projects::createProblems($source, 'bob_');

        $this->assertSame('database_name_unsupported', $problems[0]['code']);
        $this->assertStringContainsString('alice_wp$x', $problems[0]['message']);
    }

    public function test_push_problems_name_the_target_for_a_database_that_will_not_fit(): void
    {
        $source = $this->sourceWithDatabase('alice_' . str_repeat('d', 60));
        $target = $this->makeUser('bobstaging', ['mysql_prefix' => 'bobstaging_']);
        $target->staging = $source->id;
        $target->save();

        $problems = Projects::pushProblems($source->refresh(), $target->refresh());

        $this->assertSame('database_name_too_long', $problems[0]['code']);
        $this->assertSame('target', $problems[0]['field']);
    }

    public function test_push_with_nothing_selected_is_422_nothing_to_push(): void
    {
        $source = $this->makeUser('alice', ['mysql_prefix' => 'alice_']);
        $target = $this->makeUser('bob', ['mysql_prefix' => 'bob_']);
        $target->staging = $source->id;
        $target->save();

        $response = $this->postJson("/api/projects/{$source->username}/push", [
            'target' => $target->username,
            'files' => false,
            'databases' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'files');
        $response->assertJsonPath('problems.0.code', 'nothing_to_push');
    }

    public function test_staging_with_a_mapped_name_too_long_is_422_before_creation(): void
    {
        $source = $this->sourceWithDatabase('alice_' . str_repeat('d', 60));

        $response = $this->postJson("/api/projects/{$source->username}/staging", [
            'new_username' => 'bobstaging',
            'domain' => 'bobstaging.example.test',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'new_username');
        $response->assertJsonPath('problems.0.code', 'database_name_too_long');
        $this->assertSame(0, User::query()->where('username', 'bobstaging')->count());
    }

    private function sourceWithDatabase(string $database): User
    {
        $source = $this->makeUser('alice', ['mysql_prefix' => 'alice_']);
        $source->mysqlDatabases()->create(['database' => $database]);

        return $source->refresh();
    }

    private function sourceWithUser(string $user): User
    {
        $source = $this->makeUser('alice', ['mysql_prefix' => 'alice_']);
        $source->mysqlUsers()->create(['user' => $user]);

        return $source->refresh();
    }
}
