<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Jobs\DeployProject;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Async POST /projects without running a real provision / deploy.
 */
class ProjectCreateAsyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('domain')->nullable();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });

        $path = base_path('database/migrations/2026_09_04_000000_create_tasks_tables.php');
        require_once $path;
        (new \CreateTasksTables())->up();

        $this->withoutMiddleware(Authenticate::class);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('task_logs');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('users');
        \App\Models\Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_stream_header_is_rejected_before_provision(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/projects', [
            'username' => 'asyncuser',
            'email' => 'a@example.com',
        ], [
            'X-Deploy-Stream' => 'ndjson',
        ]);

        $response->assertStatus(400);
        Queue::assertNothingPushed();
    }

    public function test_validation_failure_does_not_dispatch(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/projects', [
            'username' => 'ab',
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422);
        Queue::assertNothingPushed();
    }

    /**
     * The recipe id is checked before provision, so a bad one leaves no
     * account behind -- a retry under the same name would otherwise 422.
     */
    public function test_malformed_recipe_creates_no_account(): void
    {
        Queue::fake();

        foreach (['/api/projects', '/api/users'] as $uri) {
            $response = $this->postJson($uri, [
                'username' => 'recipeuser',
                'email' => 'r@example.com',
                'domain' => 'recipeuser.test',
                'tunnel' => 'none',
                'template' => 'dind',
                'recipe' => 'Laravel',
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors('recipe');
            $this->assertFalse(User::query()->where('username', 'recipeuser')->exists(), $uri);
        }

        Queue::assertNothingPushed();
    }

    public function test_taken_username_does_not_dispatch(): void
    {
        Queue::fake();

        User::query()->create([
            'username' => 'takename',
            'domain' => 'takename.test',
            'email' => 't@example.com',
            'details' => [],
        ]);

        $response = $this->postJson('/api/projects', [
            'username' => 'takename',
            'email' => 't@example.com',
            'domain' => 'other.test',
            'tunnel' => 'none',
        ]);

        $response->assertStatus(422);
        Queue::assertNothingPushed();
        Queue::assertNotPushed(DeployProject::class);
    }

    /** #83: a caller that sent `name` hears about `name`, not `username`. */
    public function test_a_taken_name_is_reported_under_the_field_sent(): void
    {
        Queue::fake();

        User::query()->create([
            'username' => 'takenname',
            'domain' => 'takenname.test',
            'email' => 't@example.com',
            'details' => [],
        ]);

        $response = $this->postJson('/api/projects', ['name' => 'takenname', 'tunnel' => 'none']);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'name');
        $response->assertJsonPath('problems.0.code', 'name_taken');
        Queue::assertNothingPushed();
    }

    /**
     * #83: a branch the remote does not have was accepted (202) and failed the
     * deploy at clone, after the account and its domain had been made.
     */
    #[\PHPUnit\Framework\Attributes\Group('network')]
    public function test_a_branch_the_remote_lacks_is_refused_before_anything_is_created(): void
    {
        Queue::fake();
        // No git_token sent, so the probe runs anonymously.

        $response = $this->postJson('/api/projects', [
            'name' => 'branchprobe',
            'tunnel' => 'none',
            'git_repo' => 'https://github.com/octocat/Hello-World.git',
            'git_branch' => 'no-such-branch-xyz',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'git_branch');
        $response->assertJsonPath('problems.0.code', 'git_branch_not_found');
        $response->assertJsonPath('problems.0.examples.0', 'master');
        $this->assertFalse(User::query()->where('username', 'branchprobe')->exists());
        Queue::assertNothingPushed();
    }
}
