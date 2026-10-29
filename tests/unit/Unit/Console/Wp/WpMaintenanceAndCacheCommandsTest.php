<?php

namespace Tests\Unit\Console\Wp;

use App\Console\Commands\Wp\WpCacheFlushCommand;
use App\Console\Commands\Wp\WpMaintenanceGetCommand;
use App\Console\Commands\Wp\WpMaintenanceSetCommand;
use App\Http\Controllers\User\Wp\WpCacheController;
use App\Http\Controllers\User\Wp\WpMaintenanceController;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Artisan wordpress:maintenance:* / wordpress:cache:flush signatures and the documented
 * /wp/maintenance and /wp/cache/flush HTTP routes.
 */
class WpMaintenanceAndCacheCommandsTest extends TestCase
{
    public function test_artisan_maintenance_and_cache_commands_take_username_and_optional_path(): void
    {
        $cases = [
            [WpMaintenanceGetCommand::class, 'wordpress:maintenance:get', []],
            [WpMaintenanceSetCommand::class, 'wordpress:maintenance:set', ['enabled']],
            [WpCacheFlushCommand::class, 'wordpress:cache:flush', []],
        ];

        foreach ($cases as [$class, $name, $extraArgs]) {
            $command = new $class();
            $this->assertSame($name, $command->getName());
            $definition = $command->getDefinition();
            $this->assertTrue($definition->hasArgument('username'), $name);
            $this->assertTrue($definition->hasOption('path'), $name);
            foreach ($extraArgs as $arg) {
                $this->assertTrue($definition->hasArgument($arg), "{$name} missing {$arg}");
            }
        }
    }

    public function test_maintenance_and_cache_http_routes_are_registered_under_wp_prefix(): void
    {
        $expected = [
            'GET|HEAD' => [
                'api/projects/{username}/wp/maintenance',
                'api/users/{username}/wp/maintenance',
            ],
            'PUT' => [
                'api/projects/{username}/wp/maintenance',
                'api/users/{username}/wp/maintenance',
            ],
            'POST' => [
                'api/projects/{username}/wp/cache/flush',
                'api/users/{username}/wp/cache/flush',
            ],
        ];

        $found = ['GET|HEAD' => [], 'PUT' => [], 'POST' => []];
        foreach (RouteFacade::getRoutes() as $route) {
            $action = $route->getActionName();
            if (!is_string($action)) {
                continue;
            }
            $isOurs = str_starts_with($action, WpMaintenanceController::class . '@')
                || str_starts_with($action, WpCacheController::class . '@');
            if (!$isOurs) {
                continue;
            }
            $methods = $route->methods();
            $key = in_array('GET', $methods, true) ? 'GET|HEAD'
                : (in_array('PUT', $methods, true) ? 'PUT'
                : (in_array('POST', $methods, true) ? 'POST' : null));
            if ($key === null) {
                continue;
            }
            $found[$key][] = $route->uri();
        }
        foreach ($found as $k => $uris) {
            sort($uris);
            $found[$k] = $uris;
        }
        foreach ($expected as $k => $uris) {
            $sorted = $uris;
            sort($sorted);
            $this->assertSame($sorted, $found[$k], $k);
        }
    }
}
