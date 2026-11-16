<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\UnresolvedRuntimeException;

/**
 * Every `Runtime` the engine knows, discovered by convention: a class named `<Something>Runtime` in this
 * directory is a runtime, there is no list to add it to.
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
     * A manifest's `requires` resolved against a project: an optional one not found is absent, a required one
     * throws, as the manifest claimed the project on it.
     *
     * @param  array<string, array<string, mixed>>  $requires
     * @param  bool  $lenient  with no project in hand (the prewarmer), the runtime's default instead of throwing
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
                if ($lenient) {
                    $resolved[] = $runtime->defaultRequirement()->withRole(
                        is_string($spec['role'] ?? null) ? $spec['role'] : Requirement::ROLE_RUNTIME
                    );

                    continue;
                }
                throw new UnresolvedRuntimeException($id);
            }
            $role = is_string($spec['role'] ?? null) ? $spec['role'] : Requirement::ROLE_RUNTIME;
            $resolved[] = $requirement->withRole($role);
        }

        return $resolved;
    }

    /**
     * Railpack's `cpp` markers, a toolchain the engine has no runtime for (a Makefile alone is not one);
     * detection facts only, with no image to warm.
     *
     * @var list<string>
     */
    public const RAILPACK_ONLY_MARKERS = ['cmakelists.txt', 'meson.build'];

    /**
     * Does any known toolchain, or a `RAILPACK_ONLY_MARKERS` file, recognise this project? If so, and no recipe
     * claims it, Railpack is offered as a recipe the request can choose.
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
     * `anyRecognises()` answered in full, for telemetry; Railpack's markers report as `cpp`, telling "nobody
     * recognises this" from "Railpack has it and we do not".
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
