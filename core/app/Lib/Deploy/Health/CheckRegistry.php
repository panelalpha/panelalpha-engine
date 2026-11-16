<?php

namespace App\Lib\Deploy\Health;

use App\Lib\Deploy\Platform\PlatformManifest;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The checks under `resources/checks/` by group, and which of them an application is asked.
 * A manifest's `check:` list only adds to its runtime's group; `when:` subtracts.
 */
final class CheckRegistry
{
    /** The group every application is asked, whatever it runs. */
    public const BASELINE = '_baseline';

    /** Runtimes that run what the repository ships borrow the framework checks, each guarded by `when: file:`. */
    private const BORROWED_GROUPS = [
        PlatformManifest::RUNTIME_COMPOSE => PlatformManifest::RUNTIME_COMMAND,
        PlatformManifest::RUNTIME_DOCKERFILE => PlatformManifest::RUNTIME_COMMAND,
    ];

    /** @var array<string, list<HealthCheck>>|null group => checks */
    private static ?array $groups = null;

    /** @var array<string, array<string, list<HealthCheck>>> recipe directory => groups */
    private static array $recipeCache = [];

    /** Where the shipped checks live: `core/resources/checks/`. */
    public static function directory(): string
    {
        $path = __DIR__.'/../../../../resources/checks';

        return realpath($path) ?: $path;
    }

    /**
     * Every shipped check, by group.
     *
     * @return array<string, list<HealthCheck>>
     *
     * @throws CheckException
     */
    public static function all(): array
    {
        if (self::$groups !== null) {
            return self::$groups;
        }

        $root = self::directory();
        if (! is_dir($root)) {
            throw new CheckException("Health check directory not found: {$root}");
        }

        $groups = [];
        foreach (self::sorted($root) as $group) {
            $path = $root.'/'.$group;
            if (! is_dir($path) || str_starts_with($group, '.')) {
                continue;
            }
            $groups[$group] = self::load($path, $group);
        }

        return self::$groups = $groups;
    }

    /**
     * The baseline, the runtime's group and the manifest's own `check:` references (unknown ones throw).
     * A check named twice runs once.
     *
     * @param  list<string>  $references
     * @return list<HealthCheck>
     *
     * @throws CheckException
     */
    public static function for(?string $runtime, array $references = [], ?string $recipeDirectory = null): array
    {
        $groups = self::allWithDirectory($recipeDirectory);
        $selected = $groups[self::BASELINE] ?? [];

        $group = $runtime !== null ? (self::BORROWED_GROUPS[$runtime] ?? $runtime) : null;
        if ($group !== null && isset($groups[$group])) {
            $selected = array_merge($selected, $groups[$group]);
        }

        foreach ($references as $reference) {
            $selected = array_merge($selected, self::resolve($reference, $groups));
        }

        $unique = [];
        foreach ($selected as $check) {
            $unique[$check->reference()] = $check;
        }

        return array_values($unique);
    }

    /** The checks for a manifest, runtime group included. */
    public static function forManifest(PlatformManifest $manifest): array
    {
        return self::for($manifest->runtime, $manifest->checks);
    }

    /**
     * @param  array<string, list<HealthCheck>>  $groups
     * @return list<HealthCheck>
     *
     * @throws CheckException
     */
    private static function resolve(string $reference, array $groups): array
    {
        $reference = trim($reference);
        if (! str_contains($reference, '/')) {
            if (! isset($groups[$reference])) {
                throw new CheckException("Unknown check group '{$reference}'");
            }

            return $groups[$reference];
        }

        [$group, $id] = explode('/', $reference, 2);
        foreach ($groups[$group] ?? [] as $check) {
            if ($check->id === $id) {
                return [$check];
            }
        }

        throw new CheckException("Unknown check '{$reference}'");
    }

    /**
     * The shipped checks plus a recipe directory's own, merged by group; a recipe check may not shadow a shipped one.
     *
     * @return array<string, list<HealthCheck>>
     *
     * @throws CheckException
     */
    public static function allWithDirectory(?string $checksDirectory): array
    {
        $groups = self::all();
        if ($checksDirectory === null || ! is_dir($checksDirectory)) {
            return $groups;
        }

        $key = realpath($checksDirectory) ?: $checksDirectory;
        if (isset(self::$recipeCache[$key])) {
            $recipe = self::$recipeCache[$key];
        } else {
            $recipe = self::directoryGroups($checksDirectory, 'recipe');
            self::$recipeCache[$key] = $recipe;
        }

        foreach ($recipe as $group => $checks) {
            foreach ($checks as $check) {
                foreach ($groups[$group] ?? [] as $shipped) {
                    if ($shipped->id === $check->id) {
                        throw new CheckException(
                            "{$check->reference()}: a recipe check may not shadow the one the engine ships"
                        );
                    }
                }
            }
            $groups[$group] = array_merge($groups[$group] ?? [], $checks);
        }

        return $groups;
    }

    /**
     * Which of `$references` the recipe directory answers to itself, so the manifest reader does not refuse them.
     *
     * @param  list<string>  $references
     * @return list<string>
     */
    public static function recipeReferences(?string $checksDirectory, array $references): array
    {
        if ($checksDirectory === null || ! is_dir($checksDirectory) || $references === []) {
            return [];
        }

        $key = realpath($checksDirectory) ?: $checksDirectory;
        if (! isset(self::$recipeCache[$key])) {
            self::$recipeCache[$key] = self::directoryGroups($checksDirectory, 'recipe');
        }
        $groups = self::$recipeCache[$key];

        $found = [];
        foreach ($references as $reference) {
            [$group, $id] = str_contains($reference, '/')
                ? explode('/', $reference, 2)
                : [$reference, null];
            if (! isset($groups[$group])) {
                continue;
            }
            if ($id === null) {
                $found[] = $reference;

                continue;
            }
            foreach ($groups[$group] as $check) {
                if ($check->id === $id) {
                    $found[] = $reference;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * @return array<string, list<HealthCheck>>
     *
     * @throws CheckException
     */
    private static function directoryGroups(string $directory, string $source): array
    {
        $groups = [];
        foreach (self::sorted($directory) as $group) {
            $path = $directory.'/'.$group;
            if (! is_dir($path) || str_starts_with($group, '.')) {
                continue;
            }
            $groups[$group] = self::load($path, $group, $source);
            if ($groups[$group] === []) {
                unset($groups[$group]);
            }
        }

        return $groups;
    }

    /** Tests that point at a fixture directory need this. */
    public static function flush(): void
    {
        self::$groups = null;
        self::$recipeCache = [];
    }

    /**
     * A recipe's check is named as such in errors, and a file must be named for its id.
     *
     * @return list<HealthCheck>
     *
     * @throws CheckException
     */
    private static function load(string $path, string $group, string $origin = 'shipped'): array
    {
        $checks = [];
        foreach (self::sorted($path) as $entry) {
            if (! str_ends_with($entry, '.yaml')) {
                continue;
            }
            $file = $path.'/'.$entry;
            $source = ($origin === 'shipped' ? '' : "{$origin} ").$group.'/'.$entry;

            try {
                $raw = Yaml::parseFile($file);
            } catch (ParseException $e) {
                throw new CheckException("{$source}: ".$e->getMessage());
            }
            if (! is_array($raw)) {
                throw new CheckException("{$source}: expected a mapping");
            }

            $check = HealthCheck::fromArray($raw, $group, $source);
            if ($check->id.'.yaml' !== $entry) {
                throw new CheckException("{$source}: file should be named {$check->id}.yaml");
            }
            $checks[] = $check;
        }

        return $checks;
    }

    /**
     * @return list<string>
     */
    private static function sorted(string $directory): array
    {
        $entries = scandir($directory) ?: [];
        $entries = array_values(array_filter(
            $entries,
            static fn (string $e): bool => $e !== '.' && $e !== '..' && $e !== '_schema.json'
        ));
        sort($entries);

        return $entries;
    }
}
