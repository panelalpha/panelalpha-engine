<?php

namespace Tests\Unit\Console\Wp;

use App\Console\Commands\Wp\WpUserApplicationPasswordCreateCommand;
use App\Console\Commands\Wp\WpUserApplicationPasswordDeleteCommand;
use App\Console\Commands\Wp\WpUserApplicationPasswordListCommand;
use App\Http\Controllers\User\Wp\WpApplicationPasswordController;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Artisan wordpress:user:application-password:* signatures and the documented
 * /wp/users/{user}/application-passwords HTTP routes.
 */
class WpApplicationPasswordCommandsTest extends TestCase
{
    public function test_artisan_application_password_commands_take_username_and_optional_path(): void
    {
        $cases = [
            [WpUserApplicationPasswordListCommand::class, 'wordpress:user:application-password:list', ['user']],
            [WpUserApplicationPasswordCreateCommand::class, 'wordpress:user:application-password:create', ['user', 'name']],
            [WpUserApplicationPasswordDeleteCommand::class, 'wordpress:user:application-password:delete', ['user', 'uuid']],
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

        $create = (new WpUserApplicationPasswordCreateCommand())->getDefinition();
        $this->assertTrue($create->hasOption('app-id'));
    }

    public function test_application_password_http_routes_are_registered_under_wp_prefix(): void
    {
        $expected = [
            'GET|HEAD' => [
                'api/projects/{username}/wp/users/{user}/application-passwords',
                'api/users/{username}/wp/users/{user}/application-passwords',
            ],
            'POST' => [
                'api/projects/{username}/wp/users/{user}/application-passwords',
                'api/users/{username}/wp/users/{user}/application-passwords',
            ],
            'DELETE' => [
                'api/projects/{username}/wp/users/{user}/application-passwords/{uuid}',
                'api/users/{username}/wp/users/{user}/application-passwords/{uuid}',
            ],
        ];

        $found = ['GET|HEAD' => [], 'POST' => [], 'DELETE' => []];
        foreach (RouteFacade::getRoutes() as $route) {
            $action = $route->getActionName();
            if (!is_string($action) || !str_starts_with($action, WpApplicationPasswordController::class . '@')) {
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
