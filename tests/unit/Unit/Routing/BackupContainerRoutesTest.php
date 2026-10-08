<?php

namespace Tests\Unit\Routing;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class BackupContainerRoutesTest extends TestCase
{
    /**
     * @return array<string, Route>
     */
    private function backupContainerRoutes(): array
    {
        $routes = [];

        foreach (RouteFacade::getRoutes() as $route) {
            $uri = $route->uri();
            if ($uri !== 'api/backup-containers' && !str_starts_with($uri, 'api/backup-containers/')) {
                continue;
            }

            $tail = substr($uri, strlen('api/backup-containers'));

            foreach ($route->methods() as $verb) {
                if ($verb === 'HEAD') {
                    continue;
                }
                $routes["{$verb} {$tail}"] = $route;
            }
        }

        return $routes;
    }

    public function test_backup_container_routes_are_registered(): void
    {
        $routes = $this->backupContainerRoutes();

        $this->assertArrayHasKey('GET ', $routes);
        $this->assertArrayHasKey('POST ', $routes);
        $this->assertArrayHasKey('GET /{id}', $routes);
        $this->assertArrayHasKey('PUT /{id}', $routes);
        $this->assertArrayHasKey('DELETE /{id}', $routes);
        $this->assertArrayHasKey('POST /{id}/test', $routes);
        $this->assertCount(6, $routes);
    }
}
