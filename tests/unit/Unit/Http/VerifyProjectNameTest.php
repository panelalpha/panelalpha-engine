<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\System;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A taken or reserved name is an answer, not an error: an MCP client reads any
 * 4xx as a failed tool, and a batch stops at the first one.
 */
class VerifyProjectNameTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->hostReserves([]);
    }

    public function test_a_free_name_is_valid(): void
    {
        $this->verify('freename')
            ->assertOk()
            ->assertExactJson(['valid' => true]);
    }

    public function test_a_taken_name_is_answered_as_not_free(): void
    {
        $this->makeUser('takenname');

        $this->verify('takenname')
            ->assertOk()
            ->assertExactJson(['valid' => false, 'reason' => 'taken', 'message' => 'Project name already in use.']);
    }

    public function test_a_name_the_host_reserves_is_answered_as_unavailable(): void
    {
        $this->hostReserves(['backup']);

        $this->verify('backup')
            ->assertOk()
            ->assertExactJson([
                'valid' => false,
                'reason' => 'unavailable',
                'message' => 'Project name is not available on this host.',
            ]);
    }

    public function test_a_name_that_breaks_the_naming_rules_is_still_refused(): void
    {
        $this->verify('Shop-1')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['username']);
    }

    private function verify(string $name): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/projects/verify-new-username', ['username' => $name]);
    }

    /** @param list<string> $reserved */
    private function hostReserves(array $reserved): void
    {
        $this->app->instance(System::class, new class ($reserved) extends System {
            /** @param list<string> $reserved */
            public function __construct(private readonly array $reserved)
            {
            }

            public function isUsernameAvailable(string $username): bool
            {
                return !in_array($username, $this->reserved, true);
            }
        });
    }
}
