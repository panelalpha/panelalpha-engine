<?php

namespace Tests\Unit\Http;

use App\Auth\TokenAbilities;
use App\Http\Middleware\EnsureTokenMayUseApi;
use App\Mcp\Tools\Api\ApiTool;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * The REST API's half of the token boundary.
 *
 * `auth:api` checks no abilities, so until this middleware existed an
 * assistant's token reached every route on the engine and any per-command
 * limit was one curl away from meaningless.
 */
class EnsureTokenMayUseApiTest extends TestCase
{
    public function test_a_software_token_may_call_the_api(): void
    {
        $this->assertSame(200, $this->through(TokenAbilities::build(api: true, mcp: false)));
    }

    public function test_a_legacy_token_may_call_the_api(): void
    {
        $this->assertSame(200, $this->through(['*']));
        $this->assertSame(200, $this->through([]));
    }

    /**
     * `pae git:*` and the other CLI commands dispatch a route in-process as
     * the root admin, a plain authenticatable that is not the token-bearing
     * Admin model. There is no token to limit, and asking one for it used to
     * turn every such command into "HTTP 500: Server Error".
     */
    public function test_a_caller_without_a_token_api_passes(): void
    {
        $request = Request::create('/api/projects');
        $request->setUserResolver(fn () => new class extends \Illuminate\Foundation\Auth\User {
        });

        $this->assertSame(200, $this->statusOf($request));
    }

    public function test_a_request_with_no_user_passes(): void
    {
        $request = Request::create('/api/projects');
        $request->setUserResolver(fn () => null);

        $this->assertSame(200, $this->statusOf($request));
    }

    public function test_an_assistants_token_may_not(): void
    {
        $this->assertSame(403, $this->through(TokenAbilities::build(api: false, mcp: true)));
    }

    /**
     * Because that is how a tool call arrives: `ApiTool` dispatches through
     * the router in-process with the caller's bearer, and without this the
     * boundary above would break every MCP tool on the engine.
     *
     * It passes without asking anything about the token, deliberately. A call
     * is authorised by the door it arrived at, and this one arrived at `/mcp`,
     * where it was measured against what its token may call there. Re-deciding
     * it here, against a REST allow-list it was never given, would refuse
     * every tool on the engine.
     */
    public function test_but_it_may_arrive_as_a_tool_call(): void
    {
        $this->assertSame(200, $this->through(
            TokenAbilities::build(api: false, mcp: true),
            internal: true,
        ));

        // Even a limited one: its limits are the MCP ones, enforced where the
        // tool was registered rather than here.
        $this->assertSame(200, $this->through(
            TokenAbilities::build(api: true, mcp: true, routes: ['GET /nothing-like-this']),
            internal: true,
        ));
    }

    /**
     * The one that decides whether any of this is worth anything.
     *
     * `ApiTool` also sets `X-PanelAlpha-Via: mcp`, for attribution, and a
     * caller can set that header on a request of their own. The authorisation
     * marker is on the `attributes` bag instead — server-side, never populated
     * from an inbound request — so presenting the header proves nothing.
     */
    public function test_the_via_header_does_not_open_the_door(): void
    {
        $request = $this->request(TokenAbilities::build(api: false, mcp: true));
        $request->headers->set(ApiTool::VIA_HEADER, ApiTool::VIA_MCP);

        $this->assertSame(403, $this->statusOf($request));
    }

    /** An ability outside this vocabulary grants nothing on its own. */
    public function test_a_token_for_nothing_is_refused(): void
    {
        $this->assertSame(403, $this->through(['billing']));
    }

    /**
     * The per-route limit. Measured against the route Laravel matched, not
     * the URL the caller typed, so no amount of encoding turns one operation
     * into another.
     */
    public function test_a_limited_token_may_call_only_what_it_names(): void
    {
        $abilities = TokenAbilities::build(
            api: true,
            mcp: false,
            routes: ['GET /projects', 'POST /projects'],
        );

        $this->assertSame(200, $this->through($abilities, route: ['GET', 'api/projects']));
        $this->assertSame(403, $this->through($abilities, route: ['DELETE', 'api/projects/{username}']));
        $this->assertSame(403, $this->through($abilities, route: ['GET', 'api/domains']));
    }

    /**
     * `/users` is the same operation as `/projects` under its old name, so a
     * limit cannot be stepped around by asking for it that way.
     */
    public function test_the_users_alias_is_the_same_operation(): void
    {
        $abilities = TokenAbilities::build(api: true, mcp: false, routes: ['GET /projects']);

        $this->assertSame(200, $this->through($abilities, route: ['GET', 'api/users']));
    }

    /** Naming no operation is "all of them", which is not the same as none. */
    public function test_an_unlimited_api_token_may_call_anything(): void
    {
        $abilities = TokenAbilities::build(api: true, mcp: false);

        $this->assertSame(200, $this->through($abilities, route: ['DELETE', 'api/projects/{username}']));
    }

    /** A route this engine does not have cannot be on an allow-list. */
    public function test_a_limited_token_is_refused_where_no_route_matched(): void
    {
        $abilities = TokenAbilities::build(api: true, mcp: false, routes: ['GET /projects']);

        $this->assertSame(403, $this->through($abilities));
    }

    /**
     * Sanctum decides who the caller is. Anything it admits that is not a
     * personal access token is not this middleware's business to second-guess.
     */
    public function test_it_does_not_judge_what_it_was_not_asked_about(): void
    {
        $request = Request::create('/api/projects');

        $this->assertSame(200, $this->statusOf($request));
    }

    /**
     * @param array<int, string>      $abilities
     * @param array{0: string, 1: string}|null $route the verb and URI Laravel matched
     */
    private function through(array $abilities, bool $internal = false, ?array $route = null): int
    {
        $request = $this->request($abilities);

        if ($internal) {
            $request->attributes->set(ApiTool::VIA_ATTRIBUTE, true);
        }

        if ($route !== null) {
            $request->setMethod($route[0]);
            $request->setRouteResolver(fn (): Route => new Route([$route[0]], $route[1], []));
        }

        return $this->statusOf($request);
    }

    /** @param array<int, string> $abilities */
    private function request(array $abilities): Request
    {
        $admin = (new Admin(['name' => 'root']))
            ->withAccessToken(new PersonalAccessToken(['name' => 'test', 'abilities' => $abilities]));

        $request = Request::create('/api/projects');
        $request->setUserResolver(fn () => $admin);

        return $request;
    }

    private function statusOf(Request $request): int
    {
        $response = (new EnsureTokenMayUseApi())->handle(
            $request,
            fn (): Response => new Response('ok', 200)
        );

        return $response->getStatusCode();
    }
}
