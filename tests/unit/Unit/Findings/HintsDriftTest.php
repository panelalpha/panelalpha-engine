<?php

namespace Tests\Unit\Findings;

use App\Lib\Findings\Finding;
use App\Lib\Findings\FindingCodes;
use App\Lib\Findings\Hints\Hint;
use App\Lib\Findings\Hints\Hints;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/** A hint names a route, a tool and a command by hand; each must exist, or a client is sent to nothing. */
class HintsDriftTest extends TestCase
{
    public function test_every_hint_class_is_listed(): void
    {
        $classes = [];
        foreach (glob(app_path('Lib/Findings/Hints/Actions/*.php')) ?: [] as $file) {
            $class = 'App\\Lib\\Findings\\Hints\\Actions\\'.basename($file, '.php');
            if (! (new ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }
        $listed = Hints::ALL;
        sort($classes);
        sort($listed);

        $this->assertSame($classes, $listed);
    }

    public function test_every_hint_acts_on_codes_the_engine_emits(): void
    {
        foreach ($this->hints() as $hint) {
            $this->assertNotSame([], $hint->resolves(), $hint->action());
            $this->assertSame([], array_values(array_diff($hint->resolves(), FindingCodes::all())), $hint->action());
        }
    }

    public function test_only_an_unclassified_finding_has_no_hint(): void
    {
        $without = array_values(array_filter(FindingCodes::all(), static fn (string $code): bool => Hints::for($code) === []));

        $this->assertSame([Finding::UNCLASSIFIED], $without);
    }

    public function test_every_rest_operation_is_a_route(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        foreach ($this->hints() as $hint) {
            if ($hint->rest() === null) {
                continue;
            }
            [$method, $path] = explode(' ', $hint->rest(), 2);
            $uris = $routes
                ->filter(static fn ($route): bool => in_array($method, $route->methods(), true))
                ->map(static fn ($route): string => '/'.Str::after($route->uri(), 'api/'))
                ->all();

            $this->assertContains($path, $uris, $hint->action());
        }
    }

    public function test_every_rest_operation_has_its_tool(): void
    {
        $names = require app_path('Mcp/tool-names.php');
        $classes = array_map('class_basename', require app_path('Mcp/Tools/Api/generated-tools.php'));

        foreach ($this->hints() as $hint) {
            if ($hint->rest() === null) {
                continue;
            }
            $this->assertArrayHasKey($hint->rest(), $names, $hint->action());
            $this->assertContains(Str::studly($names[$hint->rest()]).'Tool', $classes, $hint->action());
        }
    }

    public function test_every_command_is_registered_with_the_arguments_and_options_it_is_given(): void
    {
        $commands = Artisan::all();

        foreach ($this->hints() as $hint) {
            if ($hint->cli() === null) {
                continue;
            }
            $words = explode(' ', $hint->cli());
            $name = array_shift($words);
            $this->assertArrayHasKey($name, $commands, $hint->action());

            $definition = $commands[$name]->getDefinition();
            $options = array_filter($words, static fn (string $word): bool => str_starts_with($word, '--'));
            foreach ($options as $option) {
                $this->assertTrue($definition->hasOption(Str::before(substr($option, 2), '=')), "{$hint->action()}: {$option}");
            }
            $this->assertLessThanOrEqual($definition->getArgumentCount(), count($words) - count($options), $hint->action());
        }
    }

    /** `username` and `source` are what every answer is about, so a command may always name them. */
    public function test_every_placeholder_is_a_parameter_of_the_hint(): void
    {
        foreach ($this->hints() as $hint) {
            preg_match_all('/\{(\w+)\}/', (string) $hint->rest(), $path);
            preg_match_all('/\{(\w+)\}/', (string) $hint->cli(), $cli);
            $known = [...$path[1], ...array_keys($hint->params()), 'username', 'source'];

            foreach ($cli[1] as $placeholder) {
                $this->assertContains($placeholder, $known, "{$hint->action()}: {{$placeholder}}");
            }
        }
    }

    /**
     * @return list<Hint>
     */
    private function hints(): array
    {
        return array_map(static fn (string $class): Hint => new $class, Hints::ALL);
    }
}
