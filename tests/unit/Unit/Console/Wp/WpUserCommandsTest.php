<?php

namespace Tests\Unit\Console\Wp;

use App\Console\Commands\Wp\WpUserCreateCommand;
use App\Console\Commands\Wp\WpUserDeleteCommand;
use App\Console\Commands\Wp\WpUserListCommand;
use App\Console\Commands\Wp\WpUserPasswordCommand;
use App\Console\Commands\Wp\WpUserUpdateCommand;
use App\Http\Controllers\User\Wp\WpUserController;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Artisan wordpress:user:* signatures and the documented /wp/users HTTP routes.
 */
class WpUserCommandsTest extends TestCase
{
    public function test_artisan_user_commands_take_username_and_optional_path(): void
    {
        $cases = [
            [WpUserListCommand::class, 'wordpress:user:list', []],
            [WpUserCreateCommand::class, 'wordpress:user:create', ['login', 'email', 'password']],
            [WpUserUpdateCommand::class, 'wordpress:user:update', ['user']],
            [WpUserDeleteCommand::class, 'wordpress:user:delete', ['user']],
            [WpUserPasswordCommand::class, 'wordpress:user:password', ['user', 'password']],
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

        $create = (new WpUserCreateCommand())->getDefinition();
        $this->assertTrue($create->hasOption('role'));
        $this->assertTrue($create->hasOption('display-name'));

        $delete = (new WpUserDeleteCommand())->getDefinition();
        $this->assertTrue($delete->hasOption('reassign'));
    }

    public function test_user_http_routes_are_registered_under_wp_prefix(): void
    {
        $expected = [
            'GET|HEAD' => [
                'api/projects/{username}/wp/users',
                'api/users/{username}/wp/users',
            ],
            'POST' => [
                'api/projects/{username}/wp/users',
                'api/projects/{username}/wp/users/{user}/sso',
                'api/users/{username}/wp/users',
                'api/users/{username}/wp/users/{user}/sso',
            ],
            'PUT' => [
                'api/projects/{username}/wp/users/{user}',
                'api/projects/{username}/wp/users/{user}/password',
                'api/users/{username}/wp/users/{user}',
                'api/users/{username}/wp/users/{user}/password',
            ],
            'DELETE' => [
                'api/projects/{username}/wp/users/{user}',
                'api/users/{username}/wp/users/{user}',
            ],
        ];

        $found = ['GET|HEAD' => [], 'POST' => [], 'PUT' => [], 'DELETE' => []];
        foreach (RouteFacade::getRoutes() as $route) {
            $action = $route->getActionName();
            if (!is_string($action) || !str_starts_with($action, WpUserController::class . '@')) {
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
