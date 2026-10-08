<?php

namespace Tests\Unit\Routing;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class BackupProjectRoutesTest extends TestCase
{
    /**
     * @return array<string, Route>
     */
    private function projectBackupRoutes(): array
    {
        $routes = [];

        foreach (RouteFacade::getRoutes() as $route) {
            $uri = $route->uri();
            if (!str_contains($uri, '/{username}/backups')) {
                continue;
            }
            if (!str_starts_with($uri, 'api/projects/')) {
                continue;
            }

            $tail = substr($uri, strlen('api/projects'));

            foreach ($route->methods() as $verb) {
                if ($verb === 'HEAD') {
                    continue;
                }
                $routes["{$verb} {$tail}"] = $route;
            }
        }

        return $routes;
    }

    public function test_project_backup_routes_are_registered(): void
    {
        $routes = $this->projectBackupRoutes();

        $this->assertArrayHasKey('GET /{username}/backups', $routes);
        $this->assertArrayHasKey('POST /{username}/backups', $routes);
        $this->assertArrayHasKey('GET /{username}/backups/{id}', $routes);
        $this->assertArrayHasKey('POST /{username}/backups/{id}/restore', $routes);
        $this->assertArrayHasKey('DELETE /{username}/backups/{id}', $routes);
        $this->assertCount(5, $routes);
    }
}
