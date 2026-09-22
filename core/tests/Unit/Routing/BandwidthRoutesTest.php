<?php

namespace Tests\Unit\Routing;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class BandwidthRoutesTest extends TestCase
{
    /**
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

    public function test_bandwidth_routes_exist_under_projects_and_users(): void
    {
        foreach (['projects', 'users'] as $prefix) {
            $routes = $this->under($prefix);
            $this->assertArrayHasKey('GET /{username}/usage', $routes);
            $this->assertArrayHasKey('GET /{username}/bandwidth', $routes);
            $this->assertArrayHasKey('GET /{username}/domains/{domain}/bandwidth', $routes);
            $this->assertArrayHasKey('GET /{username}/domains/{domain}/visitors', $routes);
            $this->assertArrayHasKey('GET /{username}/domains/{domain}/visitors/{dimension}', $routes);
        }
    }

    public function test_users_alias_reaches_the_same_bandwidth_actions(): void
    {
        $projects = $this->under('projects');
        $users = $this->under('users');

        foreach ([
            'GET /{username}/bandwidth',
            'GET /{username}/domains/{domain}/bandwidth',
            'GET /{username}/domains/{domain}/visitors',
            'GET /{username}/domains/{domain}/visitors/{dimension}',
        ] as $key) {
            $this->assertSame(
                $projects[$key]->getActionName(),
                $users[$key]->getActionName(),
                "{$key} diverges under /users"
            );
        }
    }
}
