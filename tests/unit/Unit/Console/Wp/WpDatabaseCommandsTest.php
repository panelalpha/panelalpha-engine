<?php

namespace Tests\Unit\Console\Wp;

use App\Console\Commands\Wp\WpDatabaseExportCommand;
use App\Console\Commands\Wp\WpDatabaseImportCommand;
use App\Http\Controllers\User\Wp\WpDatabaseController;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Artisan wordpress:database:* signatures and the documented /wp/database/* HTTP routes.
 */
class WpDatabaseCommandsTest extends TestCase
{
    public function test_artisan_database_commands_take_username_and_optional_path(): void
    {
        $cases = [
            [WpDatabaseExportCommand::class, 'wordpress:database:export', ['file']],
            [WpDatabaseImportCommand::class, 'wordpress:database:import', ['file']],
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

    public function test_database_http_routes_are_registered_under_wp_prefix(): void
    {
        $expected = [
            'api/projects/{username}/wp/database/export',
            'api/projects/{username}/wp/database/import',
            'api/users/{username}/wp/database/export',
            'api/users/{username}/wp/database/import',
        ];
        sort($expected);

        $found = [];
        foreach (RouteFacade::getRoutes() as $route) {
            $action = $route->getActionName();
            if (!is_string($action) || !str_starts_with($action, WpDatabaseController::class . '@')) {
                continue;
            }
            if (!in_array('POST', $route->methods(), true)) {
                continue;
            }
            $found[] = $route->uri();
        }
        sort($found);
        $this->assertSame($expected, $found);
    }
}
