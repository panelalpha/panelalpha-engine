<?php

namespace Tests\Unit\Routing;

use App\Http\Controllers\UserController;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * The hosting-account resource is spelled /projects now, but /users has to keep
 * working for clients written against the old name. routes/api.php registers one
 * closure under both prefixes so they cannot drift — except POST /, where
 * /projects is async create and /users keeps the synchronous path.
 */
class ProjectAliasRoutesTest extends TestCase
{
    /**
     * Every route under a prefix, keyed by "VERB path-without-the-prefix".
     *
     * @return array<string, Route>
     */
    private function under(string $prefix): array
    {
        $routes = [];

        foreach (RouteFacade::getRoutes() as $route) {
            $uri = $route->uri();

            if ($uri !== "api/{$prefix}" && !str_starts_with($uri, "api/{$prefix}/")) {
                continue;
            }

            $tail = substr($uri, strlen("api/{$prefix}"));

            foreach ($route->methods() as $verb) {
                if ($verb === 'HEAD') {
                    continue;
                }
                $routes["{$verb} {$tail}"] = $route;
            }
        }

        return $routes;
    }

    public function test_both_prefixes_expose_the_same_set_of_routes(): void
    {
        $projects = array_keys($this->under('projects'));
        $users = array_keys($this->under('users'));

        sort($projects);
        sort($users);

        $this->assertNotEmpty($projects, 'No /api/projects routes are registered at all');
        $this->assertSame($users, $projects, 'The /users alias no longer mirrors /projects');
    }

    public function test_the_alias_reaches_the_same_controller_action(): void
    {
        $users = $this->under('users');

        foreach ($this->under('projects') as $key => $route) {
            $this->assertArrayHasKey($key, $users, "/users is missing {$key}");

            // POST / is the deliberate exception: /projects creates async,
            // /users keeps the synchronous create.
            if ($key === 'POST ') {
                continue;
            }

            $this->assertSame(
                $route->getActionName(),
                $users[$key]->getActionName(),
                "{$key} reaches a different action under /users"
            );
        }
    }

    public function test_create_diverges_on_purpose(): void
    {
        $projects = $this->under('projects');
        $users = $this->under('users');

        $this->assertArrayHasKey('POST ', $projects);
        $this->assertArrayHasKey('POST ', $users);
        $this->assertSame(
            UserController::class . '@storeAsync',
            $projects['POST ']->getActionName()
        );
        $this->assertSame(
            UserController::class . '@store',
            $users['POST ']->getActionName()
        );
    }

    public function test_the_alias_runs_the_same_middleware(): void
    {
        $users = $this->under('users');

        foreach ($this->under('projects') as $key => $route) {
            $expected = $route->gatherMiddleware();
            $actual = $users[$key]->gatherMiddleware();

            sort($expected);
            sort($actual);

            // An alias that skipped a middleware the canonical path runs would
            // be a way around authentication, not a compatibility shim.
            $this->assertSame($actual, $expected, "{$key} runs different middleware under /users");
        }
    }

    public function test_the_canonical_spelling_is_projects(): void
    {
        $this->assertArrayHasKey('GET ', $this->under('projects'), 'GET /api/projects is not registered');
        $this->assertArrayHasKey('PUT /{username}/suspend', $this->under('projects'));
        $this->assertArrayHasKey('GET /{username}/mysql/databases', $this->under('projects'));
    }

    /**
     * mysql/users and app/users are not the renamed resource, and renaming them
     * would have silently changed what those endpoints address.
     */
    public function test_inner_user_segments_are_untouched(): void
    {
        $projects = $this->under('projects');

        $this->assertArrayHasKey('GET /{username}/mysql/users', $projects);
        $this->assertArrayHasKey('GET /{username}/app/users', $projects);
        $this->assertArrayNotHasKey('GET /{username}/mysql/projects', $projects);
        $this->assertArrayNotHasKey('GET /{username}/app/projects', $projects);
    }
}
