<?php

namespace Tests\Integration\Http;

use App\Auth\TokenAbilities;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Carbon;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * What `pae configure mcp-tokens` does to an assistant's token, over the API: a lifetime when it is minted and a
 * list of the commands it may call, never widened by a token that is itself limited.
 */
class OperatorTokensHttpTest extends TestCase
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

    public function test_an_mcp_token_is_minted_with_a_lifetime_or_none(): void
    {
        $lasting = $this->postJson('/api/mcp-tokens', ['name' => 'claude', 'expires_in' => '12h']);
        $forever = $this->postJson('/api/mcp-tokens', ['name' => 'codex']);

        $lasting->assertStatus(201);
        $this->assertSame('2026-11-17 00:00:00', PersonalAccessToken::findOrFail($lasting->json('data.id'))->expires_at?->format('Y-m-d H:i:s'));
        $this->assertNull($lasting->json('data.commands'));
        $this->assertSame(['mcp'], PersonalAccessToken::findOrFail($lasting->json('data.id'))->abilities);
        $this->assertNull($forever->json('data.expires_at'));
    }

    public function test_a_lifetime_that_is_not_a_number_and_a_unit_is_refused(): void
    {
        foreach (['3 days', '90', 'soon', 'd90'] as $lifetime) {
            $this->postJson('/api/mcp-tokens', ['name' => 'x', 'expires_in' => $lifetime])
                ->assertStatus(422)->assertJsonValidationErrors('expires_in');
        }

        $this->assertCount(0, PersonalAccessToken::all());
    }

    public function test_commands_are_limited_and_lifted(): void
    {
        $id = $this->postJson('/api/mcp-tokens', ['name' => 'claude'])->json('data.id');

        $this->putJson("/api/mcp-tokens/{$id}/commands", ['commands' => ['project_list', 'project_get']])
            ->assertOk()->assertJsonPath('data.commands', ['project_get', 'project_list']);
        $this->assertSame(['mcp', 'mcp:project_get', 'mcp:project_list'], PersonalAccessToken::findOrFail($id)->abilities);

        $this->putJson("/api/mcp-tokens/{$id}/commands", ['commands' => null])->assertOk()->assertJsonPath('data.commands', null);
    }

    public function test_the_project_scope_survives_a_command_limit(): void
    {
        $this->makeUser('alice');
        $id = $this->postJson('/api/mcp-tokens', ['name' => 'scoped', 'projects' => ['alice']])->json('data.id');

        $this->putJson("/api/mcp-tokens/{$id}/commands", ['commands' => ['project_get']])->assertOk();

        $this->assertSame(['alice'], TokenAbilities::of(PersonalAccessToken::findOrFail($id))->projects());
    }

    public function test_bad_command_input_is_refused(): void
    {
        $id = $this->postJson('/api/mcp-tokens', ['name' => 'claude'])->json('data.id');

        $this->putJson("/api/mcp-tokens/{$id}/commands", ['commands' => ['no_such_command']])->assertStatus(422)->assertJsonValidationErrors('commands.0');
        $this->putJson("/api/mcp-tokens/{$id}/commands", [])->assertStatus(422)->assertJsonValidationErrors('commands');
        $this->putJson("/api/mcp-tokens/{$id}/commands", ['commands' => []])->assertStatus(422)->assertJsonValidationErrors('commands');
        $this->putJson('/api/mcp-tokens/999999/commands', ['commands' => null])->assertStatus(404);

        $this->assertSame(['mcp'], PersonalAccessToken::findOrFail($id)->abilities);
    }

    public function test_a_limited_token_may_not_widen_a_token(): void
    {
        $id = $this->postJson('/api/mcp-tokens', ['name' => 'target'])->json('data.id');
        $this->putJson("/api/mcp-tokens/{$id}/commands", ['commands' => ['project_get']])->assertOk();
        $this->actingAsToken(TokenAbilities::build(api: true, mcp: false, routes: ['PUT /mcp-tokens/{id}/commands']));

        $this->putJson("/api/mcp-tokens/{$id}/commands", ['commands' => null])->assertStatus(403);

        $this->assertSame(['project_get'], TokenAbilities::of(PersonalAccessToken::findOrFail($id))->commands());
    }

    public function test_an_unlimited_token_may_limit_one(): void
    {
        $id = $this->postJson('/api/mcp-tokens', ['name' => 'target'])->json('data.id');
        $this->actingAsToken(TokenAbilities::build(api: true, mcp: true));

        $this->putJson("/api/mcp-tokens/{$id}/commands", ['commands' => ['project_get']])->assertOk();
    }

    /** @param  list<string>  $abilities */
    private function actingAsToken(array $abilities): void
    {
        $admin = Admin::rootAccount();
        $token = $admin->createToken('caller', $abilities)->accessToken;
        $this->actingAs($admin->withAccessToken($token));
    }
}
