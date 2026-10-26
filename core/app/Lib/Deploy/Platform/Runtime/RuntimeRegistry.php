<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Every `Runtime` the engine knows, discovered by convention: a class named
 * `<Something>Runtime` in this directory *is* a runtime, there is no list to
 * add it to.
 */
final class RuntimeRegistry
{
    /** @var array<string, Runtime>|null */
    private static ?array $runtimes = null;

    /**
     * @return array<string, Runtime> keyed by id
     */
    public static function all(): array
    {
        if (self::$runtimes !== null) {
            return self::$runtimes;
        }

        $runtimes = [];
        foreach (scandir(__DIR__) ?: [] as $entry) {
            if (! str_ends_with($entry, 'Runtime.php') || $entry === 'Runtime.php') {
                continue;
            }
            $class = __NAMESPACE__.'\\'.basename($entry, '.php');
            if (! class_exists($class) || ! is_subclass_of($class, Runtime::class)) {
                continue;
            }
            /** @var Runtime $runtime */
            $runtime = new $class;
            $runtimes[$runtime->id()] = $runtime;
        }

        ksort($runtimes);

        return self::$runtimes = $runtimes;
    }

    public static function get(string $id): Runtime
    {
        $runtime = self::all()[$id] ?? null;
        if ($runtime === null) {
            $known = implode(', ', array_keys(self::all()));
            throw new ManifestException("Unknown runtime '{$id}'; the engine knows {$known}");
        }

        return $runtime;
    }

    public static function has(string $id): bool
    {
        return isset(self::all()[$id]);
    }

    /**
     * Resolve a manifest's `requires` block against a project.
     *
     * A requirement marked optional that the runtime cannot find is absent
     * (a Laravel app with no package.json needs no Node). A required one that
     * cannot be resolved throws: the manifest claimed the project on it.
     *
     * @param  array<string, array<string, mixed>>  $requires
     * @param  bool  $lenient  substitute a runtime's default instead of throwing
     *                         when the project says nothing
     * @return list<Requirement>
     */
    public static function resolveAll(
        array $requires,
        ProjectContext $context,
        bool $lenient = false
    ): array {
        $resolved = [];
        foreach ($requires as $id => $spec) {
            $runtime = self::get((string) $id);
            $requirement = $runtime->resolve($context);
            if ($requirement === null) {
                if (($spec['optional'] ?? false) === true) {
                    continue;
                }
                // No project in hand (the prewarmer): the default, not an error.
                if ($lenient) {
                    $resolved[] = $runtime->defaultRequirement()->withRole(
                        is_string($spec['role'] ?? null) ? $spec['role'] : Requirement::ROLE_RUNTIME
                    );

                    continue;
                }
                throw new ManifestException(
                    "Runtime '{$id}' is required but could not be resolved for this project"
                );
            }
            $role = is_string($spec['role'] ?? null) ? $spec['role'] : Requirement::ROLE_RUNTIME;
            $resolved[] = $requirement->withRole($role);
        }

        return $resolved;
    }

    /**
     * Build systems Railpack has a provider for and the engine has no runtime
     * for — C and C++ today. Railpack's `cpp` provider claims a project on
     * `HasFile("CMakeLists.txt") || HasFile("meson.build")`, so a Makefile-only
     * project is not here. They are detection facts only: no image, nothing to
     * warm.
     *
     * @var list<string>
     */
    public const RAILPACK_ONLY_MARKERS = ['cmakelists.txt', 'meson.build'];

    /**
     * Does any toolchain the engine knows recognise this project? The last gate
     * before a project is written off as static or unknown: a runtime that can
     * read a manifest here means Railpack should get a chance at it.
     *
     * Derived from the runtimes, plus `RAILPACK_ONLY_MARKERS` for a toolchain
     * Railpack has and the engine does not.
     */
    public static function anyRecognises(ProjectContext $context): bool
    {
        foreach (self::all() as $runtime) {
            if ($runtime->resolve($context) !== null) {
                return true;
            }
        }

        foreach (self::RAILPACK_ONLY_MARKERS as $marker) {
            if ($context->hasFile($marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same question `anyRecognises()` asks, answered in full instead of at
     * the first hit, for telemetry: `node` distinguishes "somebody should write
     * a recipe" from "somebody should write *which* recipe".
     *
     * @return list<string> ids in the registry's order
     */
    public static function recognisedBy(ProjectContext $context): array
    {
        $ids = [];
        foreach (self::all() as $id => $runtime) {
            if ($runtime->resolve($context) !== null) {
                $ids[] = (string) $id;
            }
        }

        // Named `cpp` so telemetry can tell "nobody recognises this" from
        // "Railpack has it and we do not".
        foreach (self::RAILPACK_ONLY_MARKERS as $marker) {
            if ($context->hasFile($marker)) {
                $ids[] = 'cpp';

                break;
            }
        }

        return $ids;
    }

    /** Tests that install a fake runtime need this. */
    public static function flush(): void
    {
        self::$runtimes = null;
    }
}
