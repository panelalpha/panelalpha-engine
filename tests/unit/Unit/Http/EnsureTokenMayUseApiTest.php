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

    public function test_a_project_scoped_token_may_call_only_its_projects(): void
    {
        $abilities = TokenAbilities::build(api: true, mcp: false, projects: ['alice']);

        $this->assertSame(200, $this->through(
            $abilities,
            route: ['GET', 'api/projects/{username}'],
            params: ['username' => 'alice'],
        ));

        $response = $this->dispatched(
            $abilities,
            route: ['GET', 'api/projects/{username}'],
            params: ['username' => 'bob'],
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'This token is limited to projects alice; GET /projects/{username} is outside it.',
            $response->getData(true)['error'],
        );
    }

    public function test_a_project_scoped_token_is_refused_a_global_route(): void
    {
        $abilities = TokenAbilities::build(api: true, mcp: false, projects: ['alice']);

        $response = $this->dispatched($abilities, route: ['GET', 'api/firewall/status']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('outside it', $response->getData(true)['error']);
    }

    /** The lists are filtered in their controller, and the two reads are not about a project. */
    public function test_a_project_scoped_token_may_reach_the_routes_that_filter_or_ignore_it(): void
    {
        $abilities = TokenAbilities::build(api: true, mcp: false, projects: ['alice']);

        $this->assertSame(200, $this->through($abilities, route: ['GET', 'api/projects']));
        $this->assertSame(200, $this->through($abilities, route: ['GET', 'api/projects/all']));
        $this->assertSame(200, $this->through($abilities, route: ['GET', 'api/tasks']));
        $this->assertSame(200, $this->through($abilities, route: ['GET', 'api/system/info']));
        $this->assertSame(200, $this->through($abilities, route: ['POST', 'api/source/inspect']));
    }

    public function test_a_project_scoped_token_is_refused_the_api_access_routes(): void
    {
        $abilities = TokenAbilities::build(api: true, mcp: false, projects: ['alice']);

        foreach ([['GET', 'api/system/api-access'], ['PUT', 'api/system/api-access/{project}'], ['POST', 'api/system/api-access/{project}/rotate'], ['DELETE', 'api/system/api-access/{project}']] as $route) {
            $response = $this->dispatched($abilities, route: $route, params: ['project' => 'alice']);

            $this->assertSame(403, $response->getStatusCode(), $route[0] . ' ' . $route[1]);
        }
    }

    /** A project limit travels with the token, so an MCP tool call is bound by it too. */
    public function test_the_project_scope_is_enforced_on_a_tool_call(): void
    {
        $abilities = TokenAbilities::build(api: false, mcp: true, projects: ['alice']);

        $this->assertSame(200, $this->through(
            $abilities,
            internal: true,
            route: ['GET', 'api/projects/{username}'],
            params: ['username' => 'alice'],
        ));

        $this->assertSame(403, $this->through(
            $abilities,
            internal: true,
            route: ['GET', 'api/projects/{username}'],
            params: ['username' => 'bob'],
        ));
    }

    public function test_the_named_project_field_is_enforced_on_a_tool_call(): void
    {
        $abilities = TokenAbilities::build(api: false, mcp: true, projects: ['alice']);
        $route = ['POST', 'api/projects/{username}/push'];

        $this->assertSame(200, $this->through(
            $abilities,
            internal: true,
            route: $route,
            params: ['username' => 'alice'],
            body: ['target' => 'alice'],
        ));

        $this->assertSame(403, $this->through(
            $abilities,
            internal: true,
            route: $route,
            params: ['username' => 'alice'],
            body: ['target' => 'bob'],
        ));
    }

    /** `type: project` reads another account's files, so its `source` is a project the scope must cover. */
    public function test_a_scoped_token_may_inspect_only_its_own_project_as_a_source(): void
    {
        $abilities = TokenAbilities::build(api: true, mcp: false, projects: ['alice']);
        $route = ['POST', 'api/source/inspect'];

        $this->assertSame(200, $this->through($abilities, route: $route, body: ['source' => 'alice', 'type' => 'project']));

        $response = $this->dispatched($abilities, route: $route, body: ['source' => 'bob', 'type' => 'project']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'This token is limited to projects alice; POST /source/inspect is outside it.',
            $response->getData(true)['error'],
        );
    }

    /** With no `type`, a bare username is classified as a project, exactly as the controller does. */
    public function test_a_source_that_classifies_as_a_project_is_held_to_the_scope(): void
    {
        $abilities = TokenAbilities::build(api: true, mcp: false, projects: ['alice']);

        $this->assertSame(403, $this->through($abilities, route: ['POST', 'api/source/inspect'], body: ['source' => 'bob']));
    }

    /** A repository is not a project, whatever its path spells. */
    public function test_a_scoped_token_may_still_inspect_a_repository(): void
    {
        $abilities = TokenAbilities::build(api: true, mcp: false, projects: ['alice']);
        $route = ['POST', 'api/source/inspect'];

        $this->assertSame(200, $this->through($abilities, route: $route, body: ['source' => 'https://github.com/bob/app']));
        $this->assertSame(200, $this->through($abilities, route: $route, body: ['source' => 'github.com/bob/app']));
        $this->assertSame(200, $this->through($abilities, route: $route, body: ['source' => 'bob', 'type' => 'git']));
    }

    public function test_the_inspected_project_is_held_to_the_scope_on_a_tool_call(): void
    {
        $abilities = TokenAbilities::build(api: false, mcp: true, projects: ['alice']);

        $this->assertSame(403, $this->through(
            $abilities,
            internal: true,
            route: ['POST', 'api/source/inspect'],
            body: ['source' => 'bob', 'type' => 'project'],
        ));
    }

    public function test_the_push_diff_target_is_held_to_the_scope(): void
    {
        $abilities = TokenAbilities::build(api: true, mcp: false, projects: ['alice']);
        $route = ['GET', 'api/projects/{username}/push/diff'];

        $this->assertSame(200, $this->through($abilities, route: $route, params: ['username' => 'alice'], body: ['target' => 'alice']));
        $this->assertSame(403, $this->through($abilities, route: $route, params: ['username' => 'alice'], body: ['target' => 'bob']));
    }

    public function test_an_unscoped_token_may_inspect_any_project(): void
    {
        $abilities = TokenAbilities::build(api: true, mcp: false);

        $this->assertSame(200, $this->through($abilities, route: ['POST', 'api/source/inspect'], body: ['source' => 'bob', 'type' => 'project']));
    }

    /** Suspension is the operator's lever on a project, so its own app may not pull it. */
    public function test_a_scoped_token_may_not_suspend_or_unsuspend_its_own_project(): void
    {
        $abilities = TokenAbilities::build(api: true, mcp: false, projects: ['alice']);

        foreach (['suspend', 'unsuspend'] as $action) {
            $response = $this->dispatched(
                $abilities,
                route: ['PUT', 'api/projects/{username}/' . $action],
                params: ['username' => 'alice'],
            );

            $this->assertSame(403, $response->getStatusCode(), $action);
            $this->assertSame(
                'This token is limited to projects alice; only an unscoped token may call PUT /projects/{username}/' . $action . '.',
                $response->getData(true)['error'],
            );
        }
    }

    public function test_a_scoped_token_may_still_update_what_its_project_owns(): void
    {
        $abilities = TokenAbilities::build(api: true, mcp: false, projects: ['alice']);

        $this->assertSame(200, $this->through(
            $abilities,
            route: ['PUT', 'api/projects/{username}'],
            params: ['username' => 'alice'],
            body: ['email' => 'alice@example.test', 'domain' => 'alice.example.test'],
        ));
    }

    /** A limit cleared with null is as much a change as a raised one. */
    public function test_a_scoped_token_may_not_change_its_projects_limits(): void
    {
        $abilities = TokenAbilities::build(api: true, mcp: false, projects: ['alice']);

        $response = $this->dispatched(
            $abilities,
            route: ['PUT', 'api/projects/{username}'],
            params: ['username' => 'alice'],
            body: ['email' => 'alice@example.test', 'disk_space_limit' => -1, 'cpu_limit' => null, 'redis_config' => 'maxmemory 4gb'],
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'This token is limited to projects alice; only an unscoped token may change '
                . 'disk_space_limit, cpu_limit, redis_config on PUT /projects/{username}.',
            $response->getData(true)['error'],
        );
    }

    public function test_the_operators_fields_are_refused_on_a_tool_call(): void
    {
        $abilities = TokenAbilities::build(api: false, mcp: true, projects: ['alice']);

        $this->assertSame(403, $this->through(
            $abilities,
            internal: true,
            route: ['PUT', 'api/projects/{username}'],
            params: ['username' => 'alice'],
            body: ['memory_limit' => 8192],
        ));

        $this->assertSame(403, $this->through(
            $abilities,
            internal: true,
            route: ['PUT', 'api/projects/{username}/unsuspend'],
            params: ['username' => 'alice'],
        ));
    }

    public function test_an_unscoped_token_may_change_limits_and_suspend(): void
    {
        foreach ([TokenAbilities::build(api: true, mcp: false), ['*']] as $abilities) {
            $this->assertSame(200, $this->through(
                $abilities,
                route: ['PUT', 'api/projects/{username}'],
                params: ['username' => 'bob'],
                body: ['disk_space_limit' => -1, 'php_fpm_pool_settings' => 'pm = static'],
            ));
            $this->assertSame(200, $this->through(
                $abilities,
                route: ['PUT', 'api/projects/{username}/unsuspend'],
                params: ['username' => 'bob'],
            ));
        }
    }

    public function test_an_unscoped_token_is_not_project_refused(): void
    {
        $abilities = TokenAbilities::build(api: true, mcp: false);

        $this->assertSame(200, $this->through($abilities, route: ['GET', 'api/firewall/status']));
        $this->assertSame(200, $this->through(
            $abilities,
            route: ['GET', 'api/projects/{username}'],
            params: ['username' => 'bob'],
        ));
    }

    /**
     * @param array<int, string>               $abilities
     * @param array{0: string, 1: string}|null $route the verb and URI Laravel matched
     * @param array<string, string>            $params
     * @param array<string, mixed>             $body
     */
    private function through(
        array $abilities,
        bool $internal = false,
        ?array $route = null,
        array $params = [],
        array $body = []
    ): int {
        return $this->dispatched($abilities, $internal, $route, $params, $body)->getStatusCode();
    }

    /**
     * @param array<int, string>               $abilities
     * @param array{0: string, 1: string}|null $route the verb and URI Laravel matched
     * @param array<string, string>            $params
     * @param array<string, mixed>             $body
     */
    private function dispatched(
        array $abilities,
        bool $internal = false,
        ?array $route = null,
        array $params = [],
        array $body = []
    ): mixed {
        $request = $this->request(
            $abilities,
            $route === null ? '/api/projects' : '/' . self::pathOf($route[1], $params)
        );

        $request->merge($body);

        if ($internal) {
            $request->attributes->set(ApiTool::VIA_ATTRIBUTE, true);
        }

        if ($route !== null) {
            $request->setMethod($route[0]);
            $request->setRouteResolver(function () use ($route, $request): Route {
                $matched = new Route([$route[0]], $route[1], []);
                $matched->bind($request);

                return $matched;
            });
        }

        return (new EnsureTokenMayUseApi())->handle(
            $request,
            fn (): Response => new Response('ok', 200)
        );
    }

    /** @param array<int, string> $abilities */
    private function request(array $abilities, string $path = '/api/projects'): Request
    {
        $admin = (new Admin(['name' => 'root']))
            ->withAccessToken(new PersonalAccessToken(['name' => 'test', 'abilities' => $abilities]));

        $request = Request::create($path);
        $request->setUserResolver(fn () => $admin);

        return $request;
    }

    /** @param array<string, string> $params */
    private static function pathOf(string $uri, array $params): string
    {
        foreach ($params as $name => $value) {
            $uri = str_replace('{' . $name . '}', $value, $uri);
        }

        return $uri;
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
