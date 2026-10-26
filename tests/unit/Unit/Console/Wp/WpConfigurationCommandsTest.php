<?php

namespace Tests\Unit\Console\Wp;

use App\Console\Commands\Wp\WpAutoUpdateGetCommand;
use App\Console\Commands\Wp\WpAutoUpdateSetCommand;
use App\Console\Commands\Wp\WpConfigCreateCommand;
use App\Console\Commands\Wp\WpConfigDeleteCommand;
use App\Console\Commands\Wp\WpConfigGetCommand;
use App\Console\Commands\Wp\WpConfigListCommand;
use App\Console\Commands\Wp\WpConfigSetCommand;
use App\Console\Commands\Wp\WpOptionGetCommand;
use App\Console\Commands\Wp\WpOptionListCommand;
use App\Console\Commands\Wp\WpOptionSetCommand;
use App\Http\Controllers\User\Wp\WpAutoUpdateController;
use App\Http\Controllers\User\Wp\WpConfigController;
use App\Http\Controllers\User\Wp\WpOptionController;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Artisan wordpress:config|auto-update|option:* signatures and the
 * documented configuration HTTP routes under /wp.
 */
class WpConfigurationCommandsTest extends TestCase
{
    public function test_artisan_configuration_commands_take_username_and_optional_path(): void
    {
        $cases = [
            [WpConfigListCommand::class, 'wordpress:config:list', []],
            [WpConfigCreateCommand::class, 'wordpress:config:create', ['dbname', 'dbuser']],
            [WpConfigGetCommand::class, 'wordpress:config:get', ['constant']],
            [WpConfigSetCommand::class, 'wordpress:config:set', ['constant', 'value']],
            [WpConfigDeleteCommand::class, 'wordpress:config:delete', ['constant']],
            [WpAutoUpdateGetCommand::class, 'wordpress:auto-update:get', []],
            [WpAutoUpdateSetCommand::class, 'wordpress:auto-update:set', ['core', 'plugins', 'themes']],
            [WpOptionListCommand::class, 'wordpress:option:list', []],
            [WpOptionGetCommand::class, 'wordpress:option:get', ['option']],
            [WpOptionSetCommand::class, 'wordpress:option:set', ['option', 'value']],
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

        $setConfig = new WpConfigSetCommand();
        $this->assertTrue($setConfig->getDefinition()->hasOption('raw'));
    }

    public function test_configuration_http_routes_are_registered_under_wp_prefix(): void
    {
        $expected = [
            'GET|HEAD' => [
                'api/projects/{username}/wp/auto-update',
                'api/projects/{username}/wp/config',
                'api/projects/{username}/wp/config/{constant}',
                'api/projects/{username}/wp/options',
                'api/projects/{username}/wp/options/{option}',
                'api/users/{username}/wp/auto-update',
                'api/users/{username}/wp/config',
                'api/users/{username}/wp/config/{constant}',
                'api/users/{username}/wp/options',
                'api/users/{username}/wp/options/{option}',
            ],
            'POST' => [
                'api/projects/{username}/wp/config',
                'api/users/{username}/wp/config',
            ],
            'PUT' => [
                'api/projects/{username}/wp/auto-update',
                'api/projects/{username}/wp/config/{constant}',
                'api/projects/{username}/wp/options/{option}',
                'api/users/{username}/wp/auto-update',
                'api/users/{username}/wp/config/{constant}',
                'api/users/{username}/wp/options/{option}',
            ],
            'DELETE' => [
                'api/projects/{username}/wp/config/{constant}',
                'api/users/{username}/wp/config/{constant}',
            ],
        ];

        $controllers = [
            WpConfigController::class,
            WpAutoUpdateController::class,
            WpOptionController::class,
        ];

        $found = ['GET|HEAD' => [], 'POST' => [], 'PUT' => [], 'DELETE' => []];
        foreach (RouteFacade::getRoutes() as $route) {
            $action = $route->getActionName();
            if (!is_string($action)) {
                continue;
            }
            $isOurs = false;
            foreach ($controllers as $controller) {
                if (str_starts_with($action, $controller . '@')) {
                    $isOurs = true;
                    break;
                }
            }
            if (!$isOurs) {
                continue;
            }
            $methods = $route->methods();
            $key = in_array('GET', $methods, true) ? 'GET|HEAD'
                : (in_array('POST', $methods, true) ? 'POST'
                : (in_array('PUT', $methods, true) ? 'PUT'
                : (in_array('DELETE', $methods, true) ? 'DELETE' : null)));
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
