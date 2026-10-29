<?php

namespace Tests\Unit\Console\Wp;

use App\Console\Commands\Wp\WpLanguageSetCommand;
use App\Console\Commands\Wp\WpSearchReplaceCommand;
use App\Http\Controllers\User\Wp\WpLanguageController;
use App\Http\Controllers\User\Wp\WpSearchReplaceController;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Artisan wordpress:search-replace / wordpress:language:set signatures and the documented
 * /wp/search-replace and /wp/language HTTP routes.
 */
class WpSearchReplaceAndLanguageCommandsTest extends TestCase
{
    public function test_artisan_search_replace_and_language_commands_take_username_and_optional_path(): void
    {
        $cases = [
            [WpSearchReplaceCommand::class, 'wordpress:search-replace', ['search', 'replacement', 'extra']],
            [WpLanguageSetCommand::class, 'wordpress:language:set', ['language']],
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

    public function test_search_replace_and_language_http_routes_are_registered_under_wp_prefix(): void
    {
        $expected = [
            'PUT' => [
                'api/projects/{username}/wp/language',
                'api/users/{username}/wp/language',
            ],
            'POST' => [
                'api/projects/{username}/wp/search-replace',
                'api/users/{username}/wp/search-replace',
            ],
        ];

        $found = ['PUT' => [], 'POST' => []];
        foreach (RouteFacade::getRoutes() as $route) {
            $action = $route->getActionName();
            if (!is_string($action)) {
                continue;
            }
            $isOurs = str_starts_with($action, WpSearchReplaceController::class . '@')
                || str_starts_with($action, WpLanguageController::class . '@');
            if (!$isOurs) {
                continue;
            }
            $methods = $route->methods();
            $key = in_array('PUT', $methods, true) ? 'PUT'
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
