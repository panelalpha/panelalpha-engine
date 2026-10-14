<?php

namespace Tests\Unit\Http;

use App\Auth\TokenAbilities;
use App\Http\Middleware\Authenticate;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The panel's half of a project-scoped MCP token: it creates one with the
 * projects the user has, lists it by project, and moves it when a project
 * changes hands.
 */
class McpTokenScopeHttpTest extends TestCase
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

    public function test_a_created_token_is_scoped_to_the_projects_it_names(): void
    {
        $created = $this->postJson('/api/mcp-tokens', [
            'name' => 'Connect AI',
            'projects' => ['alice', 'bob'],
        ])->assertStatus(201);

        $this->assertEqualsCanonicalizing(['alice', 'bob'], $created->json('data.projects'));

        $listed = $this->getJson('/api/mcp-tokens?project=alice')->assertStatus(200);

        $this->assertSame([$created->json('data.id')], array_column($listed->json('data'), 'id'));

        $token = PersonalAccessToken::findOrFail($created->json('data.id'));
        $this->assertEqualsCanonicalizing(
            ['mcp', 'project', 'project:alice', 'project:bob'],
            $token->abilities,
        );
    }

    public function test_a_token_without_projects_is_unscoped(): void
    {
        $created = $this->postJson('/api/mcp-tokens', ['name' => 'Free'])->assertStatus(201);

        $this->assertNull($created->json('data.projects'));

        $listed = $this->getJson('/api/mcp-tokens?project=alice')->assertStatus(200);

        $this->assertSame([], $listed->json('data'));
    }

    public function test_an_unknown_project_is_refused(): void
    {
        $this->postJson('/api/mcp-tokens', [
            'name' => 'Connect AI',
            'projects' => ['nobody'],
        ])->assertStatus(422);
    }

    public function test_updating_the_scope_replaces_the_previous_projects(): void
    {
        $created = $this->postJson('/api/mcp-tokens', [
            'name' => 'Connect AI',
            'projects' => ['alice'],
        ])->assertStatus(201);

        $id = $created->json('data.id');

        $updated = $this->putJson("/api/mcp-tokens/{$id}/projects", ['projects' => ['bob']])
            ->assertStatus(200);

        $this->assertSame(['bob'], $updated->json('data.projects'));
        $this->assertSame([$id], array_column($this->getJson('/api/mcp-tokens?project=bob')->json('data'), 'id'));
        $this->assertSame([], $this->getJson('/api/mcp-tokens?project=alice')->json('data'));
    }

    public function test_an_empty_list_scopes_the_token_to_no_project(): void
    {
        $id = $this->postJson('/api/mcp-tokens', [
            'name' => 'Connect AI',
            'projects' => ['alice'],
        ])->assertStatus(201)->json('data.id');

        $this->putJson("/api/mcp-tokens/{$id}/projects", ['projects' => []])
            ->assertStatus(200)
            ->assertJsonPath('data.projects', []);

        $this->assertSame([], TokenAbilities::of(PersonalAccessToken::findOrFail($id))->projects());
    }

    public function test_the_scope_update_still_needs_the_projects_field(): void
    {
        $id = $this->postJson('/api/mcp-tokens', ['name' => 'Connect AI'])->assertStatus(201)->json('data.id');

        $this->putJson("/api/mcp-tokens/{$id}/projects", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('projects');
    }

    public function test_the_activity_log_can_be_filtered_by_project(): void
    {
        $this->postJson('/api/mcp-activity-logs', [
            'token_name' => 'assistant',
            'project' => 'alice',
            'tool_name' => 'project_get',
            'status' => 'success',
        ])->assertStatus(201);

        $this->postJson('/api/mcp-activity-logs', [
            'token_name' => 'assistant',
            'project' => 'bob',
            'tool_name' => 'project_get',
            'status' => 'success',
        ])->assertStatus(201);

        $filtered = $this->getJson('/api/mcp-activity-logs?project=alice')->assertStatus(200);

        $this->assertSame(['alice'], array_values(array_unique(array_column($filtered->json('data'), 'project'))));
    }

    public function test_a_project_scoped_token_cannot_manage_tokens(): void
    {
        $this->actingAsScopedToken('alice');

        $this->getJson('/api/mcp-tokens')->assertStatus(403);
        $this->postJson('/api/mcp-tokens', ['name' => 'widened'])->assertStatus(403);
        $this->putJson('/api/mcp-tokens/1/revoke')->assertStatus(403);
        $this->putJson('/api/mcp-tokens/1/projects', ['projects' => ['bob']])->assertStatus(403);
        $this->deleteJson('/api/mcp-tokens/1')->assertStatus(403);
    }

    public function test_a_project_scoped_token_cannot_widen_its_own_scope(): void
    {
        $created = $this->postJson('/api/mcp-tokens', [
            'name' => 'Connect AI',
            'projects' => ['alice'],
        ])->assertStatus(201);

        $id = $created->json('data.id');
        $this->actingAsScopedToken('alice', $id);

        $this->putJson("/api/mcp-tokens/{$id}/projects", ['projects' => ['alice', 'bob']])
            ->assertStatus(403);

        $this->assertSame(['alice'], TokenAbilities::of(PersonalAccessToken::findOrFail($id))->projects());
    }

    public function test_there_is_no_rest_route_that_mints_api_tokens(): void
    {
        $uris = array_map(
            static fn ($route): string => $route->uri(),
            Route::getRoutes()->getRoutes()
        );

        $mints = array_values(array_filter($uris, static fn (string $uri): bool => str_contains($uri, 'api-tokens')));

        $this->assertSame([], $mints);
    }

    private function actingAsScopedToken(string $project, int $id = 900501): void
    {
        $token = new PersonalAccessToken();
        $token->forceFill([
            'id' => $id,
            'tokenable_type' => Admin::class,
            'tokenable_id' => 1,
            'name' => 'scoped',
            'token' => hash('sha256', 'scoped-' . $id),
            'abilities' => TokenAbilities::build(api: true, mcp: true, projects: [$project]),
        ]);

        $admin = new Admin(['name' => 'root']);
        $admin->id = 1;
        $admin->withAccessToken($token);

        Auth::guard('api')->setUser($admin);
        Auth::setUser($admin);
        request()->setUserResolver(fn () => $admin);
    }
}
