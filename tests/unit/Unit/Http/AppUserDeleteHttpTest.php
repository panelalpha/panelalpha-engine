<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The delete endpoint checks `reassign` before it looks at the project, so a
 * bad value is refused on the field even for a project that has no app.
 */
class AppUserDeleteHttpTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->makeUser('alice', ['template' => 'php']);
    }

    public function test_a_reassign_id_longer_than_64_characters_is_refused(): void
    {
        $this->deleteJson('/api/projects/alice/app/users/7?reassign=' . str_repeat('1', 65))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reassign']);
    }

    public function test_a_valid_reassign_id_reaches_the_project_check(): void
    {
        $this->deleteJson('/api/projects/alice/app/users/7?reassign=1')
            ->assertStatus(403)
            ->assertExactJson(['message' => 'App user management works only on container projects (template dind).']);
    }
}
