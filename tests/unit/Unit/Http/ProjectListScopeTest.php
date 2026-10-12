<?php

namespace Tests\Unit\Http;

use App\Auth\TokenAbilities;
use App\Http\Middleware\Authenticate;
use App\Mcp\Tools\ProjectListSummaryTool;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request as McpRequest;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A project-limited token's whole world is its own projects, so both project
 * lists and the hand-written summary answer only those.
 */
class ProjectListScopeTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);

        $this->makeUser('alice');
        $this->makeUser('bob');
    }

    public function test_the_paginated_project_list_is_filtered_to_the_token_scope(): void
    {
        $this->actingAsScopedToken();

        $data = $this->getJson('/api/projects')->assertStatus(200)->json('data');

        $this->assertSame(['alice'], array_column($data, 'username'));
    }

    public function test_the_full_project_list_is_filtered_to_the_token_scope(): void
    {
        $this->actingAsScopedToken();

        $data = $this->getJson('/api/projects/all')->assertStatus(200)->json('data');

        $this->assertSame(['alice'], array_column($data, 'username'));
    }

    public function test_the_summary_tool_is_filtered_to_the_token_scope(): void
    {
        $this->actingAsScopedToken();

        $response = (new ProjectListSummaryTool())->handle(new McpRequest());
        $payload = json_decode((string) $response->content(), true);

        $this->assertSame(['alice'], array_column($payload['projects'], 'name'));
    }

    private function actingAsScopedToken(): void
    {
        $token = new PersonalAccessToken();
        $token->forceFill([
            'id' => 7711,
            'tokenable_type' => Admin::class,
            'tokenable_id' => 1,
            'name' => 'scoped',
            'token' => hash('sha256', 'scoped-list'),
            'abilities' => TokenAbilities::build(api: true, mcp: true, projects: ['alice']),
        ]);

        $admin = new Admin(['name' => 'root']);
        $admin->id = 1;
        $admin->withAccessToken($token);

        Auth::guard('api')->setUser($admin);
        Auth::setUser($admin);
        request()->setUserResolver(fn () => $admin);
    }
}
