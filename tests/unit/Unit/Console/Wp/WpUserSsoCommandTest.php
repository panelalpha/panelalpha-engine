<?php

namespace Tests\Unit\Console\Wp;

use App\Console\Commands\Wp\WpUserSsoCommand;
use App\Http\Controllers\User\Wp\WpUserController;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Artisan wordpress:user:sso signature and the documented /wp/users/{user}/sso route.
 */
class WpUserSsoCommandTest extends TestCase
{
    public function test_artisan_sso_command_takes_username_user_and_optional_path(): void
    {
        $command = new WpUserSsoCommand();
        $this->assertSame('wordpress:user:sso', $command->getName());
        $definition = $command->getDefinition();
        $this->assertTrue($definition->hasArgument('username'));
        $this->assertTrue($definition->hasArgument('user'));
        $this->assertTrue($definition->hasOption('path'));
    }

    public function test_sso_http_route_is_registered_under_wp_prefix(): void
    {
        $expected = [
            'api/projects/{username}/wp/users/{user}/sso',
            'api/users/{username}/wp/users/{user}/sso',
        ];
        sort($expected);

        $found = [];
        foreach (RouteFacade::getRoutes() as $route) {
            $action = $route->getActionName();
            if ($action !== WpUserController::class . '@createSso') {
                continue;
            }
            $this->assertContains('POST', $route->methods());
            $found[] = $route->uri();
        }
        sort($found);
        $this->assertSame($expected, $found);
    }
}
