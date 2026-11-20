<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A JSON body that does not parse is refused before the call does anything,
 * rather than read as a body that sent nothing.
 */
class MalformedJsonBodyTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings(['default_wildcard_domain' => 'sites.example.test']);
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_truncated_create_body_is_refused_and_nothing_is_created(): void
    {
        $response = $this->send('POST', '/api/projects', '{"name":"ftzza","email":"ft@example.com"');

        $response->assertStatus(400);
        $response->assertJsonPath('code', 'invalid_json');
        $response->assertJsonPath('problems.0.field', 'body');
        $response->assertJsonPath('problems.0.code', 'invalid_json');
        $response->assertJsonPath('message', 'The request body is not valid JSON: Syntax error.');
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Task::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_every_api_call_refuses_it_before_its_own_checks(): void
    {
        $this->send('PUT', '/api/projects/nobody', '{"email":')->assertStatus(400)->assertJsonPath('code', 'invalid_json');
        $this->send('POST', '/api/users', "{'name': 'single'}")->assertStatus(400)->assertJsonPath('code', 'invalid_json');
    }

    public function test_an_empty_or_valid_body_and_a_body_that_is_not_json_go_through(): void
    {
        $this->send('GET', '/api/projects/nobody', '')->assertStatus(404);
        $this->send('GET', '/api/projects/nobody', "  \n")->assertStatus(404);
        $this->send('PUT', '/api/projects/nobody', '{"email":"a@example.com"}')->assertStatus(404);
        $this->call('PUT', '/api/projects/nobody', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'], '{"email":')
            ->assertStatus(404);
    }

    private function send(string $method, string $uri, string $body): TestResponse
    {
        return $this->call($method, $uri, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body);
    }
}
