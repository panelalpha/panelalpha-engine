<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A clone or staging that names an existing project is refused in terms of
 * projects, the way create refuses it, over REST and the CLI alike.
 */
class TakenProjectNameRefusalTest extends TestCase
{
    use InMemoryDatabase;

    private const REFUSAL = "A project named 'taken' already exists. Choose another name.";

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->makeUser('source', ['template' => 'dind']);
        $this->makeUser('taken', ['template' => 'dind']);
    }

    public function test_a_clone_into_a_taken_name_is_refused_as_a_taken_project_name(): void
    {
        $this->postJson('/api/projects/source/clone', ['new_username' => 'taken'])
            ->assertStatus(422)
            ->assertJsonPath('errors.new_username.0', self::REFUSAL);
    }

    public function test_a_staging_into_a_taken_name_is_refused_as_a_taken_project_name(): void
    {
        $this->postJson('/api/projects/source/staging', ['new_username' => 'taken'])
            ->assertStatus(422)
            ->assertJsonPath('errors.new_username.0', self::REFUSAL);
    }

    public function test_the_staging_command_refuses_a_taken_name_in_the_same_words(): void
    {
        $this->artisan('project:staging', ['project' => 'source', '--new-username' => 'taken'])
            ->expectsOutputToContain(self::REFUSAL)
            ->assertExitCode(1);
    }
}
