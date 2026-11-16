<?php

namespace Tests\Integration\Http;

use App\Auth\TokenAbilities;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Carbon;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A token minted or changed over /api-tokens and /mcp-tokens stays within the caller's own: no surface, operation,
 * command or project it lacks, no later expiry. The caller sees and manages only tokens that stay within it, and
 * cannot widen itself. Each escalation is refused with 403 or 422 and changes nothing.
 */
class TokenScopeEscalationHttpTest extends TestCase
{
    use InMemoryDatabase;

    private const LOW_ROUTES = [
        'GET /api-tokens', 'POST /api-tokens', 'PUT /api-tokens/{id}/routes', 'PUT /api-tokens/{id}/revoke',
        'DELETE /api-tokens/{id}', 'GET /projects',
    ];

    private PersonalAccessToken $main;

    /** @var list<int> the tokens a test made itself, as opposed to the ones a request minted */
    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware();
        Carbon::setTestNow('2026-11-16 12:00:00');
        $this->main = $this->token('main', TokenAbilities::build(api: true, mcp: true));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_main_token_mints_a_lower_one_that_mints_lower_still(): void
    {
        $this->actingAsToken($this->main);
        $low = $this->postJson('/api/api-tokens', ['name' => 'low', 'routes' => self::LOW_ROUTES, 'expires_in' => '30d'])
            ->assertStatus(201)->json('data.id');

        $this->actingAsToken(PersonalAccessToken::findOrFail($low));
        $lower = $this->postJson('/api/api-tokens', ['name' => 'lower', 'routes' => ['GET /projects'], 'expires_in' => '7d']);

        $lower->assertStatus(201)->assertJsonPath('data.routes', ['GET /projects']);
        $this->assertNotEmpty($lower->json('data.plain_text_token'));
        $this->assertSame(['api', 'api:GET /projects'], PersonalAccessToken::findOrFail($lower->json('data.id'))->abilities);
    }

    public function test_a_limited_caller_may_not_mint_more_operations_than_its_own(): void
    {
        $this->actingAsToken($this->low());

        $this->postJson('/api/api-tokens', ['name' => 'all', 'expires_in' => '1d'])->assertStatus(422)->assertJsonValidationErrors('routes');
        $this->postJson('/api/api-tokens', ['name' => 'more', 'expires_in' => '1d', 'routes' => ['GET /projects', 'DELETE /projects/{username}']])
            ->assertStatus(422)->assertJsonValidationErrors('routes');
        $this->postJson('/api/api-tokens', ['name' => 'null', 'expires_in' => '1d', 'routes' => null])->assertStatus(422)->assertJsonValidationErrors('routes');

        $this->assertMinted(0);
    }

    public function test_a_caller_that_expires_may_not_mint_one_that_outlives_it(): void
    {
        $this->actingAsToken($this->low());

        $this->postJson('/api/api-tokens', ['name' => 'forever', 'routes' => ['GET /projects']])->assertStatus(422)->assertJsonValidationErrors('expires_in');
        $this->postJson('/api/api-tokens', ['name' => 'longer', 'routes' => ['GET /projects'], 'expires_in' => '31d'])->assertStatus(422)->assertJsonValidationErrors('expires_in');
        $this->postJson('/api/api-tokens', ['name' => 'exact', 'routes' => ['GET /projects'], 'expires_in' => '30d'])->assertStatus(201);

        $this->assertMinted(1);
    }

    public function test_a_caller_without_mcp_may_not_mint_an_mcp_token(): void
    {
        $this->actingAsToken($this->token('api-only', TokenAbilities::build(api: true, mcp: false, routes: ['POST /mcp-tokens'])));

        $this->postJson('/api/mcp-tokens', ['name' => 'assistant'])->assertStatus(403);
        $this->postJson('/api/mcp-tokens', ['name' => 'assistant', 'commands' => ['project_list']])->assertStatus(403);

        $this->assertMinted(0);
    }

    public function test_a_caller_limited_to_some_commands_mints_only_those(): void
    {
        $this->actingAsToken($this->token('assistant-admin', TokenAbilities::build(api: true, mcp: true, commands: ['project_list', 'project_get'])));

        $this->postJson('/api/mcp-tokens', ['name' => 'all'])->assertStatus(422)->assertJsonValidationErrors('commands');
        $this->postJson('/api/mcp-tokens', ['name' => 'more', 'commands' => ['project_list', 'project_delete']])->assertStatus(422)->assertJsonValidationErrors('commands');
        $this->assertMinted(0);

        $this->postJson('/api/mcp-tokens', ['name' => 'fewer', 'commands' => ['project_list']])
            ->assertStatus(201)->assertJsonPath('data.commands', ['project_list']);
    }

    public function test_a_limited_caller_sees_only_tokens_within_its_own(): void
    {
        $low = $this->low();
        $child = $this->child();
        $this->token('sibling', TokenAbilities::build(api: true, mcp: false, routes: ['GET /projects', 'DELETE /projects/{username}']), Carbon::now()->addDay());
        $this->actingAsToken($low);

        $listed = array_column($this->getJson('/api/api-tokens')->assertOk()->json('data'), 'id');

        $this->assertSame([$low->id, $child->id], $listed);
    }

    public function test_a_limited_caller_may_not_touch_a_token_wider_than_its_own(): void
    {
        $this->actingAsToken($this->low());
        $id = $this->main->id;

        $this->putJson("/api/api-tokens/{$id}/routes", ['routes' => ['GET /projects']])->assertStatus(403);
        $this->putJson("/api/api-tokens/{$id}/revoke")->assertStatus(403);
        $this->deleteJson("/api/api-tokens/{$id}")->assertStatus(403);

        $main = PersonalAccessToken::findOrFail($id);
        $this->assertSame(['api', 'mcp'], $main->abilities);
        $this->assertNull($main->revoked_at);
    }

    public function test_a_limited_caller_may_narrow_a_lower_token_but_never_widen_it(): void
    {
        $child = $this->child();
        $this->actingAsToken($this->low());

        $this->putJson("/api/api-tokens/{$child->id}/routes", ['routes' => null])->assertStatus(422)->assertJsonValidationErrors('routes');
        $this->putJson("/api/api-tokens/{$child->id}/routes", ['routes' => ['GET /projects', 'DELETE /projects/{username}']])
            ->assertStatus(422)->assertJsonValidationErrors('routes');
        $this->assertSame(['GET /projects'], TokenAbilities::of($child->refresh())->routes());

        $this->putJson("/api/api-tokens/{$child->id}/routes", ['routes' => ['GET /projects', 'GET /api-tokens']])->assertOk();
        $this->putJson("/api/api-tokens/{$child->id}/revoke")->assertOk();
        $this->deleteJson("/api/api-tokens/{$child->id}")->assertOk();
    }

    public function test_a_caller_may_not_widen_itself(): void
    {
        $low = $this->low();
        $this->actingAsToken($low);

        $this->putJson("/api/api-tokens/{$low->id}/routes", ['routes' => null])->assertStatus(422);
        $this->putJson("/api/api-tokens/{$low->id}/routes", ['routes' => [...self::LOW_ROUTES, 'DELETE /projects/{username}']])->assertStatus(422);

        $this->assertEqualsCanonicalizing(self::LOW_ROUTES, TokenAbilities::of($low->refresh())->routes());
    }

    public function test_a_caller_limited_to_some_commands_may_not_lift_or_touch_a_wider_mcp_token(): void
    {
        $unlimited = $this->token('assistant', TokenAbilities::build(api: false, mcp: true));
        $narrow = $this->token('narrow', TokenAbilities::build(api: false, mcp: true, commands: ['project_list']));
        $this->actingAsToken($this->token('assistant-admin', TokenAbilities::build(api: true, mcp: true, commands: ['project_list', 'project_get'])));

        $this->putJson("/api/mcp-tokens/{$narrow->id}/commands", ['commands' => null])->assertStatus(422)->assertJsonValidationErrors('commands');
        $this->putJson("/api/mcp-tokens/{$unlimited->id}/commands", ['commands' => ['project_list']])->assertStatus(403);
        $this->putJson("/api/mcp-tokens/{$unlimited->id}/projects", ['projects' => []])->assertStatus(403);
        $this->putJson("/api/mcp-tokens/{$unlimited->id}/revoke")->assertStatus(403);
        $this->deleteJson("/api/mcp-tokens/{$unlimited->id}")->assertStatus(403);
        $this->assertNotContains($unlimited->id, array_column($this->getJson('/api/mcp-tokens')->json('data'), 'id'));

        $this->assertSame(['mcp'], $unlimited->refresh()->abilities);
        $this->assertNull($unlimited->revoked_at);
        $this->assertSame(['project_list'], TokenAbilities::of($narrow->refresh())->commands());
    }

    public function test_an_api_caller_limited_to_the_mcp_token_routes_may_not_reach_an_mcp_token(): void
    {
        $this->makeUser('alice');
        $assistant = $this->token('assistant', TokenAbilities::build(api: false, mcp: true, projects: ['alice']));
        $this->actingAsToken($this->token('api-only', TokenAbilities::build(api: true, mcp: false, routes: [
            'POST /mcp-tokens', 'PUT /mcp-tokens/{id}/projects', 'PUT /mcp-tokens/{id}/revoke', 'DELETE /mcp-tokens/{id}',
        ])));

        $this->postJson('/api/mcp-tokens', ['name' => 'wide'])->assertStatus(403);
        $this->putJson("/api/mcp-tokens/{$assistant->id}/projects", ['projects' => ['alice']])->assertStatus(403);
        $this->putJson("/api/mcp-tokens/{$assistant->id}/revoke")->assertStatus(403);
        $this->deleteJson("/api/mcp-tokens/{$assistant->id}")->assertStatus(403);

        $this->assertSame(['alice'], TokenAbilities::of($assistant->refresh())->projects());
        $this->assertNull($assistant->revoked_at);
        $this->assertMinted(0);
    }

    public function test_the_plaintext_is_answered_once_and_never_listed(): void
    {
        $this->actingAsToken($this->low());
        $this->postJson('/api/api-tokens', ['name' => 'lower', 'routes' => ['GET /projects'], 'expires_in' => '1d'])->assertStatus(201);

        foreach ($this->getJson('/api/api-tokens')->json('data') as $listed) {
            $this->assertArrayNotHasKey('plain_text_token', $listed);
            $this->assertArrayNotHasKey('token', $listed);
        }
    }

    /** The lower-scope token the main one hands out: some of the API, for 30 days. */
    private function low(): PersonalAccessToken
    {
        return $this->token('low', TokenAbilities::build(api: true, mcp: false, routes: self::LOW_ROUTES), Carbon::now()->addDays(30));
    }

    private function child(): PersonalAccessToken
    {
        return $this->token('child', TokenAbilities::build(api: true, mcp: false, routes: ['GET /projects']), Carbon::now()->addDay());
    }

    /** @param  list<string>  $abilities */
    private function token(string $name, array $abilities, ?Carbon $expiresAt = null): PersonalAccessToken
    {
        $token = Admin::rootAccount()->createToken($name, $abilities, $expiresAt)->accessToken;
        assert($token instanceof PersonalAccessToken);
        $this->fixtures[] = $token->id;

        return $token;
    }

    private function actingAsToken(PersonalAccessToken $token): void
    {
        $this->actingAs(Admin::rootAccount()->withAccessToken($token));
    }

    private function assertMinted(int $count): void
    {
        $this->assertSame($count, PersonalAccessToken::query()->whereNotIn('id', $this->fixtures)->count());
    }
}
