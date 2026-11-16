<?php

namespace Tests\Integration\Http;

use App\Auth\TokenAbilities;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Carbon;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The tokens `pae configure api-tokens` manages, over the API: minted, narrowed, revoked and deleted the same way.
 * What a limited caller may do is in {@see TokenScopeEscalationHttpTest}.
 */
class ApiTokensHttpTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware();
        Carbon::setTestNow('2026-11-16 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_mint_answers_the_plaintext_once_and_the_list_never(): void
    {
        $response = $this->postJson('/api/api-tokens', ['name' => 'deploy-bot', 'expires_in' => '90d']);

        $response->assertStatus(201);
        $this->assertNotEmpty($response->json('data.plain_text_token'));
        $this->assertSame('deploy-bot', $response->json('data.name'));
        $this->assertNull($response->json('data.routes'));
        $this->assertSame('2027-02-14', substr((string) $response->json('data.expires_at'), 0, 10));
        $this->assertSame(['api'], PersonalAccessToken::findOrFail($response->json('data.id'))->abilities);

        $listed = $this->getJson('/api/api-tokens')->assertOk()->json('data');
        $this->assertSame(['deploy-bot'], array_column($listed, 'name'));
        $this->assertArrayNotHasKey('plain_text_token', $listed[0]);
    }

    public function test_mint_takes_a_route_limit_and_a_project_scope(): void
    {
        $this->makeUser('alice');

        $response = $this->postJson('/api/api-tokens', ['name' => 'ci', 'routes' => ['GET /projects'], 'projects' => ['alice']]);

        $response->assertStatus(201);
        $this->assertSame(['GET /projects'], $response->json('data.routes'));
        $this->assertSame(['alice'], $response->json('data.projects'));
    }

    public function test_the_api_list_leaves_out_assistant_tokens_and_keeps_legacy_ones(): void
    {
        $root = Admin::rootAccount();
        $root->createToken('assistant', TokenAbilities::build(api: false, mcp: true));
        $root->createToken('old', ['*']);

        $listed = $this->getJson('/api/api-tokens')->assertOk()->json('data');

        $this->assertSame(['old'], array_column($listed, 'name'));
        $this->assertTrue($listed[0]['legacy']);
    }

    public function test_routes_are_limited_lifted_and_emptied(): void
    {
        $id = $this->postJson('/api/api-tokens', ['name' => 'ci'])->json('data.id');

        $this->putJson("/api/api-tokens/{$id}/routes", ['routes' => ['GET /projects', 'GET /system/info']])
            ->assertOk()->assertJsonPath('data.routes', ['GET /projects', 'GET /system/info']);
        $this->assertSame(['GET /projects', 'GET /system/info'], TokenAbilities::of(PersonalAccessToken::findOrFail($id))->routes());

        $this->putJson("/api/api-tokens/{$id}/routes", ['routes' => null])->assertOk()->assertJsonPath('data.routes', null);
        $this->assertSame(['api'], PersonalAccessToken::findOrFail($id)->abilities);
    }

    public function test_revoke_keeps_the_token_listed_and_delete_removes_it(): void
    {
        $id = $this->postJson('/api/api-tokens', ['name' => 'ci'])->json('data.id');

        $this->putJson("/api/api-tokens/{$id}/revoke")->assertOk()->assertJsonPath('data.revoked_at', fn ($at): bool => $at !== null);
        $this->assertTrue(PersonalAccessToken::findOrFail($id)->isRevoked());

        $this->deleteJson("/api/api-tokens/{$id}")->assertOk()->assertExactJson(['data' => ['id' => $id]]);
        $this->assertNull(PersonalAccessToken::find($id));
    }

    public function test_an_assistant_token_is_not_found_among_the_api_tokens(): void
    {
        $assistant = Admin::rootAccount()->createToken('assistant', TokenAbilities::build(api: false, mcp: true))->accessToken;

        $this->putJson("/api/api-tokens/{$assistant->id}/revoke")->assertStatus(404);
        $this->deleteJson("/api/api-tokens/{$assistant->id}")->assertStatus(404);
        $this->assertNull(PersonalAccessToken::findOrFail($assistant->id)->revoked_at);
    }

    public function test_bad_input_is_refused(): void
    {
        $this->postJson('/api/api-tokens', [])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/api-tokens', ['name' => 'x', 'expires_in' => 'soon'])->assertStatus(422)->assertJsonValidationErrors('expires_in');
        $this->postJson('/api/api-tokens', ['name' => 'x', 'routes' => ['GET /nowhere']])->assertStatus(422)->assertJsonValidationErrors('routes.0');
        $this->postJson('/api/api-tokens', ['name' => 'x', 'projects' => ['nobody']])->assertStatus(422)->assertJsonValidationErrors('projects.0');

        $id = $this->postJson('/api/api-tokens', ['name' => 'ci'])->json('data.id');
        $this->putJson("/api/api-tokens/{$id}/routes", [])->assertStatus(422)->assertJsonValidationErrors('routes');

        $this->assertCount(1, PersonalAccessToken::all());
    }

    public function test_an_unlimited_token_may_mint(): void
    {
        $this->actingAsToken(TokenAbilities::build(api: true, mcp: false));

        $this->postJson('/api/api-tokens', ['name' => 'next'])->assertStatus(201);
    }

    /** @param  list<string>  $abilities */
    private function actingAsToken(array $abilities): void
    {
        $admin = Admin::rootAccount();
        $token = $admin->createToken('caller', $abilities)->accessToken;
        $this->actingAs($admin->withAccessToken($token));
    }
}
