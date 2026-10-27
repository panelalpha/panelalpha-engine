<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Mcp\Tools\Api\Projects\ProjectVerifyNameTool;
use App\System;
use ReflectionMethod;
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

    public function test_a_request_without_a_name_is_refused_not_a_server_error(): void
    {
        $this->postJson('/api/projects/verify-new-username', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['username']);
    }

    /** Over MCP the argument is `name`, so the sentence must not name the REST field. */
    public function test_a_tool_call_without_a_name_is_not_told_about_username(): void
    {
        $tool = new ProjectVerifyNameTool();
        $rename = new ReflectionMethod($tool, 'withArgumentNames');

        foreach ([[], ['username' => 5]] as $body) {
            $response = $this->postJson('/api/projects/verify-new-username', $body)->assertStatus(422);
            $renamed = $rename->invoke($tool, $response->json());

            $this->assertStringNotContainsString('username', (string) json_encode($renamed));
            $this->assertSame(['name'], array_keys($renamed['errors']));
            $this->assertSame('name', $renamed['problems'][0]['field']);
        }
        $this->postJson('/api/projects/verify-new-username', [])
            ->assertJsonPath('errors.username.0', 'A project name is required.');
    }

    /** Project create takes `name` for `username`, and so does the check made before it. */
    public function test_name_is_accepted_like_on_project_create(): void
    {
        $this->makeUser('takenname');

        $this->postJson('/api/projects/verify-new-username', ['name' => 'freename'])
            ->assertOk()
            ->assertExactJson(['valid' => true]);
        $this->postJson('/api/projects/verify-new-username', ['name' => 'takenname'])
            ->assertOk()
            ->assertJson(['valid' => false, 'reason' => 'taken']);
    }

    public function test_a_bad_name_sent_as_name_is_refused_under_name(): void
    {
        $this->postJson('/api/projects/verify-new-username', ['name' => 'Shop-1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name'])
            ->assertJsonPath('problems.0.code', 'name_invalid');
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
