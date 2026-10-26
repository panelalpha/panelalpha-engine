<?php

namespace Tests\Unit\Hub;

use App\Auth\ApiSurface;
use App\Auth\TokenAbilities;
use App\Lib\Hub\HubGrants;
use App\Models\HubAccessLog;
use App\Models\HubConnection;
use App\Models\PersonalAccessToken;
use App\Models\TokenRotation;
use Illuminate\Testing\TestResponse;

/**
 * The Hub reads the regular API with a token limited to a few routes, and
 * rotates or ends that token through two generic routes: A7, A11, A12, A19.
 */
class HubTokenApiTest extends HubTestCase
{
    /** Sanctum keeps the first caller for the whole test; each request here is its own. */
    public function withToken(string $token, string $type = 'Bearer'): static
    {
        $this->app['auth']->forgetGuards();

        return parent::withToken($token, $type);
    }

    /** A7: exactly base, or base + admin_sso; never `mcp`, `*` or another route. */
    public function test_a_hub_token_carries_exactly_the_granted_routes(): void
    {
        $this->assertSame([
            'api',
            'api:DELETE /tokens/current',
            'api:GET /metrics/current',
            'api:GET /metrics/history',
            'api:GET /projects/all',
            'api:GET /projects/{username}/backups',
            'api:GET /projects/{username}/containers/metrics',
            'api:GET /projects/{username}/metrics/history',
            'api:GET /system/info',
            'api:POST /tokens/current/rotate',
        ], HubGrants::abilities([]));

        $sso = HubGrants::abilities(['admin_sso']);
        $this->assertContains('api:GET /projects/{username}/app/users', $sso);
        $this->assertContains('api:POST /projects/{username}/app/users/{userId}/sso', $sso);
        $this->assertCount(12, $sso);

        foreach ([HubGrants::abilities([]), $sso] as $abilities) {
            $this->assertNotContains('*', $abilities);
            $this->assertNotContains(TokenAbilities::MCP, $abilities);
            $this->assertTrue(HubGrants::isHubScoped($abilities));
        }

        $this->assertFalse(HubGrants::isHubScoped(['*']));
        $this->assertFalse(HubGrants::isHubScoped(['api']));
        $this->assertFalse(HubGrants::isHubScoped(['api', 'mcp', 'api:GET /system/info']));
        $this->assertFalse(HubGrants::isHubScoped(['api', 'api:GET /system/info', 'api:POST /projects']));
    }

    /** A grant that names a route the router does not have would be a silent hole. */
    public function test_every_granted_route_exists(): void
    {
        ApiSurface::forget();
        $routes = array_merge(HubGrants::BASE_ROUTES, ...array_values(HubGrants::OPTIONAL_ROUTES));

        $this->assertSame([], array_values(array_diff($routes, ApiSurface::all())));
    }

    /** WP6 */
    public function test_no_hub_specific_route_remains(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $this->assertStringStartsNotWith('api/hub', $route->uri());
        }
    }

    /** A19 */
    public function test_a_hub_token_reaches_its_routes_and_nothing_else(): void
    {
        $this->project('shop');
        $token = $this->hubToken(['admin_sso'])->plainTextToken;

        foreach ([
            ['GET', '/api/metrics/current'],
            ['GET', '/api/metrics/history?range=1h'],
            ['GET', '/api/projects/all'],
            ['GET', '/api/projects/shop/backups'],
            ['GET', '/api/projects/shop/metrics/history?range=1h'],
            ['GET', '/api/projects/shop/containers/metrics'],
            ['GET', '/api/projects/shop/app/users'],
            ['POST', '/api/projects/shop/app/users/1/sso'],
        ] as [$method, $uri]) {
            $this->assertNotRefusedByAbility($this->withToken($token)->json($method, $uri), "{$method} {$uri}");
        }

        foreach ([
            ['GET', '/api/projects/shop/files/download?path=/etc/passwd'],
            ['POST', '/api/projects'],
            ['GET', '/api/projects'],
            ['GET', '/api/projects/shop'],
            ['PUT', '/api/system/update'],
            ['GET', '/api/mcp-tokens'],
        ] as [$method, $uri]) {
            $this->assertRefusedByAbility($this->withToken($token)->json($method, $uri), "{$method} {$uri}");
        }

        // /mcp answers 401 to a token without `mcp`, per the MCP spec.
        $this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertUnauthorized();
    }

    public function test_without_admin_sso_the_app_user_routes_are_refused(): void
    {
        $this->project('shop');
        $token = $this->hubToken()->plainTextToken;

        $this->assertRefusedByAbility($this->withToken($token)->getJson('/api/projects/shop/app/users'), 'app users');
        $this->assertRefusedByAbility($this->withToken($token)->postJson('/api/projects/shop/app/users/1/sso'), 'sso');
    }

    /** A11 */
    public function test_rotation_keeps_the_name_and_abilities_and_is_idempotent_in_the_grace(): void
    {
        $old = $this->hubToken(['admin_sso']);
        $connection = $this->connection($old, ['admin_sso']);

        $rotated = $this->withToken($old->plainTextToken)->postJson('/api/tokens/current/rotate')->assertOk()->json('data');
        $again = $this->withToken($old->plainTextToken)->postJson('/api/tokens/current/rotate')->assertOk()->json('data');

        $this->assertSame($rotated, $again, 'a retry within the grace period gets the same token');
        $this->assertSame(['token', 'token_id', 'abilities', 'expires_at'], array_keys($rotated));
        $this->assertSame($old->accessToken->abilities, $rotated['abilities']);
        /** @var PersonalAccessToken $new */
        $new = PersonalAccessToken::query()->findOrFail($rotated['token_id']);
        $this->assertSame('hub:hc_TEST', $new->name);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $new->expires_at->timestamp, 5);
        $this->assertSame($rotated['token_id'], $connection->fresh()->token_id);
        $this->assertSame(2, PersonalAccessToken::query()->where('name', 'hub:hc_TEST')->count());

        $this->withToken($rotated['token'])->getJson('/api/projects/all')->assertOk();
        $this->withToken($old->plainTextToken)->getJson('/api/projects/all')->assertOk();

        $this->travel(11)->minutes();
        $this->withToken($old->plainTextToken)->getJson('/api/projects/all')->assertUnauthorized();
        $this->withToken($rotated['token'])->getJson('/api/projects/all')->assertOk();

        $this->artisan('model:prune', ['--model' => [TokenRotation::class]]);
        $this->assertSame(0, TokenRotation::query()->count(), 'the plain token is gone after the grace');
    }

    public function test_any_token_may_rotate_itself(): void
    {
        $plain = \App\Models\Admin::rootAccount()->createToken('my-script', TokenAbilities::build(api: true, mcp: false))->plainTextToken;

        $rotated = $this->withToken($plain)->postJson('/api/tokens/current/rotate')->assertOk()->json('data');

        $this->assertSame(['api'], $rotated['abilities']);
        $this->assertSame('my-script', PersonalAccessToken::query()->find($rotated['token_id'])->name);
    }

    /** A12 */
    public function test_revoking_a_hub_token_also_forgets_its_connection(): void
    {
        $token = $this->hubToken();
        $connection = $this->connection($token);

        $this->withToken($token->plainTextToken)->deleteJson('/api/tokens/current')->assertNoContent();

        $this->assertNotNull($token->accessToken->fresh()->revoked_at);
        $this->assertNull($connection->fresh());
        $this->withToken($token->plainTextToken)->getJson('/api/projects/all')->assertUnauthorized();
    }

    /** A12: `--replace` -- the Hub ends the old token while the new connection completes. */
    public function test_revoking_an_old_hub_token_leaves_a_newer_connection_alone(): void
    {
        $old = $this->hubToken([], 'hc_OLD');
        $new = $this->hubToken([], 'hc_NEW');
        $connection = $this->connection($new, [], 'hc_NEW');

        $this->withToken($old->plainTextToken)->deleteJson('/api/tokens/current')->assertNoContent();

        $this->assertNotNull($old->accessToken->fresh()->revoked_at);
        $this->assertNull($new->accessToken->fresh()->revoked_at);
        $this->assertNotNull($connection->fresh());
    }

    /** A12 */
    public function test_revoking_another_token_leaves_the_connection_alone(): void
    {
        $connection = $this->connection($this->hubToken());
        $plain = \App\Models\Admin::rootAccount()->createToken('my-script', ['api'])->plainTextToken;

        $this->withToken($plain)->deleteJson('/api/tokens/current')->assertNoContent();

        $this->assertNotNull($connection->fresh());
    }

    public function test_calls_with_a_hub_token_are_logged_refused_ones_too(): void
    {
        $this->project('shop');
        $token = $this->hubToken(['admin_sso']);
        $connection = $this->connection($token, ['admin_sso']);

        $this->withToken($token->plainTextToken)->getJson('/api/projects/all')->assertOk();
        $this->withToken($token->plainTextToken)->getJson('/api/projects')->assertForbidden();
        $this->withToken($token->plainTextToken)->postJson('/api/projects/shop/app/users/7/sso');
        $this->withToken($this->adminToken())->getJson('/api/projects/all')->assertOk();

        $rows = HubAccessLog::query()->orderBy('id')->get();
        $this->assertSame(
            ['GET /projects/all', 'GET /projects', 'POST /projects/{username}/app/users/{userId}/sso'],
            $rows->pluck('route')->all()
        );
        $this->assertSame([200, 403], $rows->take(2)->pluck('status')->all());
        $this->assertSame('shop', $rows[2]->app);
        $this->assertSame('app user 7', $rows[2]->detail);
        $this->assertSame('127.0.0.1', $rows[0]->source_ip);
        $this->assertNotNull($connection->fresh()->last_hub_call_at);
    }

    private function assertRefusedByAbility(TestResponse $response, string $what): void
    {
        $this->assertSame(403, $response->getStatusCode(), $what);
        $this->assertStringContainsString('This token is limited to part of the API', (string) $response->json('error'), $what);
    }

    private function assertNotRefusedByAbility(TestResponse $response, string $what): void
    {
        $this->assertStringNotContainsString(
            'This token is limited to part of the API',
            (string) $response->json('error'),
            "{$what} answered {$response->getStatusCode()}"
        );
        $this->assertNotSame(401, $response->getStatusCode(), $what);
    }
}
