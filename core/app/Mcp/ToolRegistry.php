<?php

namespace App\Mcp;

use App\Mcp\Servers\EngineServer;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;

/**
 * Every tool the engine knows how to expose, before any configuration is
 * applied: the hand-written ones declared on {@see EngineServer}, plus the
 * generated registry it merges in.
 *
 * Read from the server class rather than restated, so a tool added there is a
 * tool `mcp:tool:list` and `pae configure` see without being told.
 */
class ToolRegistry
{
    /** @var array<int, class-string<Tool>>|null */
    private static ?array $cached = null;

    /**
     * @return array<int, class-string<Tool>>
     */
    public static function all(): array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $declared = (new ReflectionClass(EngineServer::class))
            ->getProperty('tools')
            ->getDefaultValue();

        $generated = app_path('Mcp/Tools/Api/generated-tools.php');

        return self::$cached = array_merge($declared, is_file($generated) ? require $generated : []);
    }

    /**
     * Every tool, keyed by the toolset it belongs to and sorted by name inside
     * each group — the shape both the listing and the wizard want.
     *
     * @return array<string, array<int, class-string<Tool>>>
     */
    public static function byToolset(ToolPolicy $policy): array
    {
        $groups = [];

        foreach (self::all() as $class) {
            $groups[$policy->toolsetOf($class)][] = $class;
        }

        foreach ($groups as $toolset => $classes) {
            usort($classes, fn (string $a, string $b): int => $policy->nameOf($a) <=> $policy->nameOf($b));
            $groups[$toolset] = $classes;
        }

        ksort($groups);

        return $groups;
    }

    /** Only for tests, which build servers with different tool sets. */
    public static function forget(): void
    {
        self::$cached = null;
    }
}
