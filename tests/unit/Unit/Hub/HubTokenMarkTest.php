<?php

namespace Tests\Unit\Hub;

use App\Auth\TokenAbilities;
use App\Lib\Hub\HubGrants;
use App\Lib\Hub\HubTokens;
use App\Models\Admin;
use App\Models\HubAccessLog;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/** A Hub token is one HubTokens minted, not any token someone named `hub:...`. */
class HubTokenMarkTest extends HubTestCase
{
    private const MIGRATION = 'migrations/2026_10_26_000000_add_hub_to_personal_access_tokens.php';

    /** Sanctum keeps the first caller for the whole test; each request here is its own. */
    public function withToken(string $token, string $type = 'Bearer'): static
    {
        $this->app['auth']->forgetGuards();

        return parent::withToken($token, $type);
    }

    public function test_a_disconnect_leaves_tokens_only_named_like_the_hubs(): void
    {
        Sleep::fake();
        $hub = $this->hubToken();
        $this->connection($hub);
        $mcp = $this->withToken($this->adminToken())
            ->postJson('/api/mcp-tokens', ['name' => 'hub: staging bot'])->assertCreated()->json('data.id');
        $api = Admin::rootAccount()->createToken('hub:ci', TokenAbilities::build(api: true, mcp: false))->accessToken;
        Http::fake([self::HUB.'/*' => Http::response(null, 204)]);

        $this->artisan('hub:disconnect')->assertExitCode(0);

        $this->assertNotNull($hub->accessToken->fresh()->revoked_at);
        $this->assertNull(PersonalAccessToken::query()->findOrFail($mcp)->revoked_at);
        $this->assertNull($api->fresh()->revoked_at);
    }

    public function test_a_new_connection_revokes_only_earlier_hub_tokens(): void
    {
        $earlier = $this->hubToken([], 'hc_OLD');
        $minted = HubTokens::mint(HubGrants::abilities([]));
        $named = Admin::rootAccount()->createToken('hub:hc_OLD', TokenAbilities::build(api: true, mcp: false))->accessToken;

        $this->assertSame(1, HubTokens::revokeAll(except: (int) $minted->accessToken->id));

        $this->assertNotNull($earlier->accessToken->fresh()->revoked_at);
        $this->assertNull($minted->accessToken->fresh()->revoked_at);
        $this->assertNull($named->fresh()->revoked_at);
    }

    public function test_calls_with_a_token_only_named_like_the_hubs_are_not_logged(): void
    {
        $plain = Admin::rootAccount()->createToken('hub:ci', TokenAbilities::build(api: true, mcp: false))->plainTextToken;

        $this->withToken($plain)->getJson('/api/projects/all')->assertOk();
        $this->withToken($plain)->getJson('/api/projects')->assertOk();

        $this->assertSame(0, HubAccessLog::query()->count());
    }

    public function test_a_rotation_carries_the_hub_mark_and_only_the_hub_mark(): void
    {
        $hub = $this->hubToken();
        $connection = $this->connection($hub);
        $named = Admin::rootAccount()->createToken('hub:hc_TEST', TokenAbilities::build(api: true, mcp: false))->plainTextToken;

        $rotated = $this->withToken($hub->plainTextToken)->postJson('/api/tokens/current/rotate')->assertOk()->json('data');
        $impostor = $this->withToken($named)->postJson('/api/tokens/current/rotate')->assertOk()->json('data');

        $this->assertTrue(HubTokens::isHubToken(PersonalAccessToken::query()->find($rotated['token_id'])));
        $this->assertFalse(HubTokens::isHubToken(PersonalAccessToken::query()->find($impostor['token_id'])));
        $this->assertSame($rotated['token_id'], $connection->fresh()->token_id);
    }

    public function test_tokens_already_issued_to_the_hub_are_marked_on_upgrade(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->down();
        $root = Admin::rootAccount();
        $connected = $root->createToken('hub:hc_TEST', HubGrants::abilities(['admin_sso']))->accessToken;
        $pending = $root->createToken(HubTokens::PENDING, HubGrants::abilities([]))->accessToken;
        $root->createToken('hub: staging bot', TokenAbilities::build(api: false, mcp: true));
        $root->createToken('hub:ci', TokenAbilities::build(api: true, mcp: false));
        $root->createToken('my-script', HubGrants::abilities([]));

        $migration->up();

        $this->assertSame(
            [$connected->id, $pending->id],
            PersonalAccessToken::query()->where('hub', true)->orderBy('id')->pluck('id')->all()
        );
    }
}
