<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** What a missing project answers: one lookup, one 404 "Project not found". */
class MissingProjectResponseTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
    }

    public function test_find_by_username_or_fail_throws_for_a_missing_project(): void
    {
        $this->expectException(ModelNotFoundException::class);

        User::findByUsernameOrFail('nobody');
    }

    public function test_find_by_username_or_fail_returns_the_project_when_it_exists(): void
    {
        $this->makeUser('alice');

        $this->assertSame('alice', User::findByUsernameOrFail('alice')->username);
    }

    public function test_a_missing_project_is_a_404_saying_project_not_found(): void
    {
        $response = $this->getJson('/api/projects/nobody');

        $response->assertStatus(404);
        $response->assertExactJson(['message' => 'Project not found']);
    }

    public function test_the_legacy_users_prefix_answers_the_same_way(): void
    {
        $response = $this->getJson('/api/users/nobody');

        $response->assertStatus(404);
        $response->assertExactJson(['message' => 'Project not found']);
    }

    /** Endpoints that used to answer a plain "Not found" now name the project too. */
    public function test_every_project_endpoint_names_the_project(): void
    {
        foreach (['cron-jobs', 'inspect', 'mysql/users', 'deploy-log', 'git/status'] as $path) {
            $response = $this->getJson('/api/projects/nobody/' . $path);

            $response->assertStatus(404);
            $response->assertExactJson(['message' => 'Project not found']);
        }
    }

    public function test_find_by_username_or_fail_answers_project_not_found(): void
    {
        Route::get('/api/test-missing-project', static fn () => User::findByUsernameOrFail('nobody'));

        $this->getJson('/api/test-missing-project')
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Project not found']);
    }

    /** A project that does exist must not reach the not-found branch at all. */
    public function test_an_existing_project_is_not_reported_missing(): void
    {
        $this->makeUser('alice');

        $this->getJson('/api/projects/alice')->assertStatus(200, 'happy path must not 404');
    }
}
