<?php

namespace Tests\Unit\Console\Wp;

use App\Console\Commands\Wp\WpCoreCheckUpdateCommand;
use App\Console\Commands\Wp\WpCoreDownloadCommand;
use App\Console\Commands\Wp\WpCoreInstallCommand;
use App\Console\Commands\Wp\WpCoreStatusCommand;
use App\Console\Commands\Wp\WpCoreUpdateCommand;
use App\Http\Controllers\User\Wp\WpCoreController;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Artisan wordpress:core:* signatures and the documented /wp/core HTTP routes.
 */
class WpCoreCommandsTest extends TestCase
{
    public function test_artisan_core_commands_take_username_and_optional_path(): void
    {
        $cases = [
            [WpCoreStatusCommand::class, 'wordpress:core:status', []],
            [WpCoreInstallCommand::class, 'wordpress:core:install', ['url', 'title', 'admin_user', 'admin_email', 'admin_password']],
            [WpCoreUpdateCommand::class, 'wordpress:core:update', ['version']],
            [WpCoreCheckUpdateCommand::class, 'wordpress:core:check-update', []],
            [WpCoreDownloadCommand::class, 'wordpress:core:download', []],
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

    public function test_core_http_routes_are_registered_under_wp_prefix(): void
    {
        $expected = [
            'GET|HEAD' => [
                'api/projects/{username}/wp/core',
                'api/projects/{username}/wp/core/updates',
                'api/users/{username}/wp/core',
                'api/users/{username}/wp/core/updates',
            ],
            'POST' => [
                'api/projects/{username}/wp/core/download',
                'api/projects/{username}/wp/core/install',
                'api/projects/{username}/wp/core/update',
                'api/users/{username}/wp/core/download',
                'api/users/{username}/wp/core/install',
                'api/users/{username}/wp/core/update',
            ],
        ];

        $found = ['GET|HEAD' => [], 'POST' => []];
        foreach (RouteFacade::getRoutes() as $route) {
            $action = $route->getActionName();
            if (!is_string($action) || !str_starts_with($action, WpCoreController::class . '@')) {
                continue;
            }
            $methods = $route->methods();
            $key = in_array('GET', $methods, true) ? 'GET|HEAD'
                : (in_array('POST', $methods, true) ? 'POST' : null);
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
