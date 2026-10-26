<?php

namespace App\Lib\Deploy\Platform\Runtime;

use Symfony\Component\Yaml\Yaml;

/**
 * The `runtimes` block of `config/core/images.yaml`, parsed once per process:
 * which image a
 * runtime runs at a given version, and whether the engine builds it.
 *
 * Every accessor answers null for a missing file, a malformed one, or an entry
 * that does not describe what was asked, and every caller falls back to its own
 * constants. This is customer-editable config on a host that must keep
 * deploying: a bad edit loses the customisation, never the deploy.
 *
 * No Laravel dependencies — the path is relative to this file, so tests read
 * the shipped config without booting the app.
 */
final class RuntimeImageCatalog
{
    private const CONFIG_PATH = __DIR__.'/../../../../../../config/core/images.yaml';

    /** The only placeholder the format has, substituted into `from`. */
    private const VERSION_PLACEHOLDER = '{version}';

    /** @var array<string, array<string, mixed>>|null runtime id => entry */
    private static ?array $runtimes = null;

    /**
     * Tests only. The branches that keep a host deploying are the ones the
     * shipped config — the good case by definition — never reaches.
     */
    private static ?string $configPath = null;

    public static function has(string $runtime): bool
    {
        return isset(self::runtimes()[$runtime]);
    }

    public static function spec(string $runtime, string $version): ?RuntimeImageSpec
    {
        $image = self::runtimes()[$runtime]['image'] ?? null;
        if (! is_array($image)) {
            return null;
        }

        // Java's versions are build tools, not numbers: `21-maven` is
        // maven:3-eclipse-temurin-21 and `21-gradle` is gradle:8-jdk21. One
        // template cannot express two families, so a version may name its own.
        $from = self::overrideString($image, $version, 'from') ?? self::nonEmptyString($image, 'from');
        if ($from === null) {
            return null;
        }
        $from = str_replace(self::VERSION_PLACEHOLDER, $version, $from);
        if (! self::isSafeRef($from)) {
            return null;
        }

        // A separate image for a host compile, when the runtime is the wrong
        // one to build in. Validated the same way as `from`: this value is
        // handed to `docker run`, so anything that is not a plain image
        // reference is dropped rather than passed on. Dropping it falls back
        // to `from` -- a compile in the runtime image, which is what the
        // engine did before this key existed.
        $buildFrom = self::overrideString($image, $version, 'build_from')
            ?? self::nonEmptyString($image, 'build_from');
        if ($buildFrom !== null) {
            $buildFrom = str_replace(self::VERSION_PLACEHOLDER, $version, $buildFrom);
            if (! self::isSafeRef($buildFrom)) {
                $buildFrom = null;
            }
        }

        $build = $image['build'] ?? null;
        if (! is_array($build)) {
            return new RuntimeImageSpec($runtime, $version, $from, buildFrom: $buildFrom);
        }

        $repository = self::nonEmptyString($build, 'repository');
        $stub = self::nonEmptyString($build, 'stub');
        // A repository with no stub names an image nothing knows how to
        // produce, and provisioning would rebuild-loop on it. Pull-only.
        if ($repository === null || $stub === null
            || ! self::isSafeRef($repository) || ! self::isSafeStub($stub)
        ) {
            return new RuntimeImageSpec($runtime, $version, $from, buildFrom: $buildFrom);
        }

        return new RuntimeImageSpec(
            $runtime,
            $version,
            $from,
            $repository,
            $stub,
            ($build['runnable'] ?? false) === true,
            $buildFrom
        );
    }

    /** What a project that names no version gets. */
    public static function defaultVersion(string $runtime): ?string
    {
        $default = self::nonEmptyString(self::runtimes()[$runtime] ?? [], 'default');
        if ($default === null) {
            return null;
        }
        // A default outside the declared set is what every project saying
        // nothing would be handed, with nothing else in the file to contradict
        // it.
        $versions = self::versions($runtime);

        return $versions === [] || in_array($default, $versions, true) ? $default : null;
    }

    /**
     * The versions a resolver may choose between, in declared order — resolvers
     * walk them oldest-first and take the first that satisfies the project.
     *
     * @return list<string>
     */
    public static function versions(string $runtime): array
    {
        $declared = self::runtimes()[$runtime]['versions'] ?? null;
        if (! is_array($declared)) {
            return [];
        }

        $versions = [];
        $seen = [];
        foreach ($declared as $entry) {
            // An entry is `- version: "8.3"` with whatever else that version
            // carries -- its prewarm priority, its note. A bare string is
            // accepted too: it is the same statement with nothing attached,
            // and it is what a config written before the files were merged
            // says.
            $version = is_array($entry) ? ($entry['version'] ?? null) : $entry;
            if (! is_string($version) && ! is_int($version) && ! is_float($version)) {
                continue;
            }
            $version = trim((string) $version);
            // YAML reads an unquoted `8.3` as a float, and `8.30` would come
            // back "8.3"; versions are documented as quoted strings and
            // anything not shaped like one is dropped rather than guessed.
            if (preg_match('/^[0-9]+(?:\.[0-9]+){0,2}(?:-[a-z0-9._-]+)?$/i', $version) !== 1) {
                continue;
            }
            // Kept in a separate set because PHP turns a numeric-string array
            // key into an int: as a key, Rust's sole version "1" came back as
            // int 1 and stopped matching the string callers compare against.
            if (isset($seen[$version])) {
                continue;
            }
            $seen[$version] = true;
            $versions[] = $version;
        }

        return $versions;
    }

    /**
     * Where the engine puts the image it builds for this runtime, or null when
     * it builds none. Every PHP minor lands in `panelalpha/php`, so this is
     * per-runtime and a caller need not invent a version to ask.
     */
    public static function repository(string $runtime): ?string
    {
        return self::buildString($runtime, 'repository', self::isSafeRef(...));
    }

    /** The Dockerfile stub the engine builds that image from. */
    public static function stub(string $runtime): ?string
    {
        return self::buildString($runtime, 'stub', self::isSafeStub(...));
    }

    /**
     * The date stamped into the tags of the images built for this runtime, as
     * `Ymd`. Bump it in the config when you change the recipe -- the stub, the
     * extension list, the entrypoint -- and the next run builds a new image
     * instead of serving what is already on the host under the same name.
     *
     * Null when the runtime declares none, and the compiled-in date stands.
     */
    public static function recipeDate(string $runtime): ?string
    {
        $declared = self::buildString($runtime, 'recipe', static fn (string $v): bool => preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $v) === 1 && strtotime($v) !== false);

        return $declared === null ? null : str_replace('-', '', $declared);
    }

    /**
     * Is the built image the runtime itself, rather than an optimisation over a
     * stock image that works without it? Decides whether a deploy waits for the
     * build or carries on while it runs in the background.
     *
     * False when the catalogue does not say: carrying on is always survivable,
     * blocking on an unasked-for build is not.
     */
    public static function runnable(string $runtime): bool
    {
        $build = self::runtimes()[$runtime]['image']['build'] ?? null;

        return is_array($build) && ($build['runnable'] ?? false) === true;
    }

    /**
     * The official repository a runtime's images are built from — `php` out of
     * `php:{version}-apache-bookworm`.
     */
    public static function upstreamRepository(string $runtime): ?string
    {
        $from = self::nonEmptyString(self::runtimes()[$runtime]['image'] ?? [], 'from');
        if ($from === null) {
            return null;
        }
        $repository = explode(':', $from, 2)[0];

        return self::isSafeRef($repository) && ! str_contains($repository, self::VERSION_PLACEHOLDER)
            ? $repository
            : null;
    }

    /**
     * @return array<string, string> repository => runtime id
     */
    public static function builtRepositories(): array
    {
        $repositories = [];
        foreach (array_keys(self::runtimes()) as $runtime) {
            $repository = self::repository($runtime);
            if ($repository !== null) {
                $repositories[$repository] = $runtime;
            }
        }

        return $repositories;
    }

    /** Point at a fixture, or back at the shipped config with null. Tests only. */
    public static function useConfig(?string $path): void
    {
        self::$configPath = $path;
        self::$runtimes = null;
    }

    public static function flush(): void
    {
        self::$runtimes = null;
    }

    /**
     * @param  callable(string): bool  $isValid
     */
    private static function buildString(string $runtime, string $key, callable $isValid): ?string
    {
        $build = self::runtimes()[$runtime]['image']['build'] ?? null;
        if (! is_array($build)) {
            return null;
        }
        $value = self::nonEmptyString($build, $key);

        return $value !== null && $isValid($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $image  the runtime's `image` block
     */
    private static function overrideString(array $image, string $version, string $key): ?string
    {
        $override = $image['overrides'][$version] ?? null;

        return is_array($override) ? self::nonEmptyString($override, $key) : null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function nonEmptyString(array $entry, string $key): ?string
    {
        $value = $entry[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * A whitelist of what a registry reference looks like, not a blacklist of
     * what a shell would mind: this value comes from an operator's file and
     * reaches `docker build -t`.
     */
    private static function isSafeRef(string $ref): bool
    {
        return preg_match('#^[a-z0-9][a-z0-9._/-]*(?::[a-z0-9][a-z0-9._-]*)?$#i', $ref) === 1;
    }

    /** A stub is a path under `resources/deploy/templates/` and may not climb out. */
    private static function isSafeStub(string $stub): bool
    {
        return ! str_contains($stub, '..')
            && preg_match('#^[a-z0-9][a-z0-9._-]*(?:/[a-z0-9][a-z0-9._-]*)*$#i', $stub) === 1;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function runtimes(): array
    {
        if (self::$runtimes !== null) {
            return self::$runtimes;
        }

        return self::$runtimes = self::load();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function load(): array
    {
        $path = self::$configPath ?? self::CONFIG_PATH;
        if (! is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }
        try {
            $parsed = Yaml::parse($raw);
        } catch (\Exception $e) {
            // The operator's problem to see in their editor, not a deploy's to
            // fail on.
            return [];
        }
        if (! is_array($parsed) || ! isset($parsed['runtimes']) || ! is_array($parsed['runtimes'])) {
            return [];
        }

        $runtimes = [];
        foreach ($parsed['runtimes'] as $id => $entry) {
            if (is_string($id) && $id !== '' && is_array($entry)) {
                $runtimes[$id] = $entry;
            }
        }

        return $runtimes;
    }
}
