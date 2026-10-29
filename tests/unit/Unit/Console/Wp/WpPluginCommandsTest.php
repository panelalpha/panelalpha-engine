<?php

namespace Tests\Unit\Console\Wp;

use App\Console\Commands\Wp\WpPluginActivateCommand;
use App\Console\Commands\Wp\WpPluginDeactivateCommand;
use App\Console\Commands\Wp\WpPluginDeleteCommand;
use App\Console\Commands\Wp\WpPluginGetCommand;
use App\Console\Commands\Wp\WpPluginInstallCommand;
use App\Console\Commands\Wp\WpPluginIsInstalledCommand;
use App\Console\Commands\Wp\WpPluginListCommand;
use App\Console\Commands\Wp\WpPluginSearchCommand;
use App\Console\Commands\Wp\WpPluginUpdateCommand;
use App\Http\Controllers\User\Wp\WpPluginController;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Artisan wordpress:plugin:* signatures and the documented /wp/plugins HTTP routes.
 */
class WpPluginCommandsTest extends TestCase
{
    public function test_artisan_plugin_commands_take_username_and_optional_path(): void
    {
        $cases = [
            [WpPluginListCommand::class, 'wordpress:plugin:list', false, false],
            [WpPluginInstallCommand::class, 'wordpress:plugin:install', true, true],
            [WpPluginActivateCommand::class, 'wordpress:plugin:activate', true, false],
            [WpPluginDeactivateCommand::class, 'wordpress:plugin:deactivate', true, false],
            [WpPluginUpdateCommand::class, 'wordpress:plugin:update', true, false],
            [WpPluginDeleteCommand::class, 'wordpress:plugin:delete', true, false],
            [WpPluginSearchCommand::class, 'wordpress:plugin:search', false, false],
            [WpPluginGetCommand::class, 'wordpress:plugin:get', true, false],
            [WpPluginIsInstalledCommand::class, 'wordpress:plugin:is-installed', true, false],
        ];

        foreach ($cases as [$class, $name, $hasSlug, $hasForce]) {
            $command = new $class();
            $this->assertSame($name, $command->getName());
            $definition = $command->getDefinition();
            $this->assertTrue($definition->hasArgument('username'), $name);
            $this->assertTrue($definition->hasOption('path'), $name);
            if ($hasSlug) {
                $this->assertTrue($definition->hasArgument('slug'), $name);
            }
            if ($name === 'wordpress:plugin:search') {
                $this->assertTrue($definition->hasArgument('term'), $name);
            }
            if ($hasForce) {
                $this->assertTrue($definition->hasOption('force'), $name);
            }
        }
    }

    public function test_plugin_http_routes_are_registered_under_wp_prefix(): void
    {
        $expected = [
            'GET|HEAD' => [
                'api/projects/{username}/wp/plugins',
                'api/projects/{username}/wp/plugins/search',
                'api/projects/{username}/wp/plugins/{plugin}',
                'api/projects/{username}/wp/plugins/{plugin}/installed',
                'api/users/{username}/wp/plugins',
                'api/users/{username}/wp/plugins/search',
                'api/users/{username}/wp/plugins/{plugin}',
                'api/users/{username}/wp/plugins/{plugin}/installed',
            ],
            'POST' => [
                'api/projects/{username}/wp/plugins/install',
                'api/projects/{username}/wp/plugins/{plugin}/activate',
                'api/projects/{username}/wp/plugins/{plugin}/deactivate',
                'api/projects/{username}/wp/plugins/{plugin}/update',
                'api/users/{username}/wp/plugins/install',
                'api/users/{username}/wp/plugins/{plugin}/activate',
                'api/users/{username}/wp/plugins/{plugin}/deactivate',
                'api/users/{username}/wp/plugins/{plugin}/update',
            ],
            'DELETE' => [
                'api/projects/{username}/wp/plugins/{plugin}',
                'api/users/{username}/wp/plugins/{plugin}',
            ],
        ];

        $found = ['GET|HEAD' => [], 'POST' => [], 'DELETE' => []];
        foreach (RouteFacade::getRoutes() as $route) {
            $action = $route->getActionName();
            if (!is_string($action) || !str_starts_with($action, WpPluginController::class . '@')) {
                continue;
            }
            $methods = $route->methods();
            $key = in_array('GET', $methods, true) ? 'GET|HEAD'
                : (in_array('POST', $methods, true) ? 'POST'
                : (in_array('DELETE', $methods, true) ? 'DELETE' : null));
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
