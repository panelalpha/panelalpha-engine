<?php

namespace Tests\Unit\Console\Wp;

use App\Console\Commands\Wp\WpThemeActivateCommand;
use App\Console\Commands\Wp\WpThemeDeleteCommand;
use App\Console\Commands\Wp\WpThemeGetCommand;
use App\Console\Commands\Wp\WpThemeInstallCommand;
use App\Console\Commands\Wp\WpThemeIsInstalledCommand;
use App\Console\Commands\Wp\WpThemeListCommand;
use App\Console\Commands\Wp\WpThemeSearchCommand;
use App\Console\Commands\Wp\WpThemeUpdateCommand;
use App\Http\Controllers\User\Wp\WpThemeController;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Artisan wordpress:theme:* signatures and the documented /wp/themes HTTP routes.
 */
class WpThemeCommandsTest extends TestCase
{
    public function test_artisan_theme_commands_take_username_and_optional_path(): void
    {
        $cases = [
            [WpThemeListCommand::class, 'wordpress:theme:list', false, false],
            [WpThemeInstallCommand::class, 'wordpress:theme:install', true, true],
            [WpThemeActivateCommand::class, 'wordpress:theme:activate', true, false],
            [WpThemeUpdateCommand::class, 'wordpress:theme:update', true, false],
            [WpThemeDeleteCommand::class, 'wordpress:theme:delete', true, false],
            [WpThemeSearchCommand::class, 'wordpress:theme:search', false, false],
            [WpThemeGetCommand::class, 'wordpress:theme:get', true, false],
            [WpThemeIsInstalledCommand::class, 'wordpress:theme:is-installed', true, false],
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
            if ($name === 'wordpress:theme:search') {
                $this->assertTrue($definition->hasArgument('term'), $name);
            }
            if ($hasForce) {
                $this->assertTrue($definition->hasOption('force'), $name);
            }
        }
    }

    public function test_theme_http_routes_are_registered_under_wp_prefix(): void
    {
        $expected = [
            'GET|HEAD' => [
                'api/projects/{username}/wp/themes',
                'api/projects/{username}/wp/themes/search',
                'api/projects/{username}/wp/themes/{theme}',
                'api/projects/{username}/wp/themes/{theme}/installed',
                'api/users/{username}/wp/themes',
                'api/users/{username}/wp/themes/search',
                'api/users/{username}/wp/themes/{theme}',
                'api/users/{username}/wp/themes/{theme}/installed',
            ],
            'POST' => [
                'api/projects/{username}/wp/themes/install',
                'api/projects/{username}/wp/themes/{theme}/activate',
                'api/projects/{username}/wp/themes/{theme}/update',
                'api/users/{username}/wp/themes/install',
                'api/users/{username}/wp/themes/{theme}/activate',
                'api/users/{username}/wp/themes/{theme}/update',
            ],
            'DELETE' => [
                'api/projects/{username}/wp/themes/{theme}',
                'api/users/{username}/wp/themes/{theme}',
            ],
        ];

        $found = ['GET|HEAD' => [], 'POST' => [], 'DELETE' => []];
        foreach (RouteFacade::getRoutes() as $route) {
            $action = $route->getActionName();
            if (!is_string($action) || !str_starts_with($action, WpThemeController::class . '@')) {
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
