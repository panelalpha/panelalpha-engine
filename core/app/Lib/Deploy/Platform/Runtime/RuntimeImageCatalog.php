<?php

namespace App\Lib\Deploy\Platform\Runtime;

use Symfony\Component\Yaml\Yaml;

/**
 * The `runtimes` block of `config/core/images.yaml`, parsed once per process: each runtime's image per version.
 * Anything missing or malformed answers null and callers fall back: a bad edit loses the customisation, never the deploy.
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

        $from = self::fromFor($image, $version);
        if ($from === null) {
            return null;
        }
        $buildFrom = self::buildFromFor($image, $version);

        return self::specFor($image, $runtime, $version, $from, $buildFrom);
    }

    /**
     * The safe `from` for a version, which may name its own: Java's `21-maven` and `21-gradle` are two families.
     *
     * @param  array<string, mixed>  $image
     */
    private static function fromFor(array $image, string $version): ?string
    {
        $from = self::overrideString($image, $version, 'from') ?? self::nonEmptyString($image, 'from');
        if ($from === null) {
            return null;
        }
        $from = str_replace(self::VERSION_PLACEHOLDER, $version, $from);
        if (! self::isSafeRef($from)) {
            return null;
        }

        return $from;
    }

    /**
     * A host-compile image, validated like `from` since it reaches `docker run`; null compiles in `from`.
     *
     * @param  array<string, mixed>  $image
     */
    private static function buildFromFor(array $image, string $version): ?string
    {
        $buildFrom = self::overrideString($image, $version, 'build_from')
            ?? self::nonEmptyString($image, 'build_from');
        if ($buildFrom !== null) {
            $buildFrom = str_replace(self::VERSION_PLACEHOLDER, $version, $buildFrom);
            if (! self::isSafeRef($buildFrom)) {
                $buildFrom = null;
            }
        }

        return $buildFrom;
    }

    /**
     * The spec, built by the engine only with a safe repository and stub: a stub-less one would rebuild-loop.
     *
     * @param  array<string, mixed>  $image
     */
    private static function specFor(array $image, string $runtime, string $version, string $from, ?string $buildFrom): RuntimeImageSpec
    {
        $build = $image['build'] ?? null;
        if (! is_array($build)) {
            return new RuntimeImageSpec($runtime, $version, $from, buildFrom: $buildFrom);
        }

        $repository = self::nonEmptyString($build, 'repository');
        $stub = self::nonEmptyString($build, 'stub');
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

    /** What a project that names no version gets; refused outside the declared set, which nothing would contradict. */
    public static function defaultVersion(string $runtime): ?string
    {
        $default = self::nonEmptyString(self::runtimes()[$runtime] ?? [], 'default');
        if ($default === null) {
            return null;
        }
        $versions = self::versions($runtime);

        return $versions === [] || in_array($default, $versions, true) ? $default : null;
    }

    /**
     * The versions in declared order, which resolvers walk oldest-first; deduplicated through a separate set,
     * since a numeric-string key turns into an int (Rust's "1").
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
            $version = self::versionOf($entry);
            if ($version === null) {
                continue;
            }
            if (isset($seen[$version])) {
                continue;
            }
            $seen[$version] = true;
            $versions[] = $version;
        }

        return $versions;
    }

    /**
     * An entry's version, from `- version: "8.3"` or a bare string; null unless shaped like one, since YAML reads
     * an unquoted `8.30` as 8.3.
     */
    private static function versionOf(mixed $entry): ?string
    {
        $version = is_array($entry) ? ($entry['version'] ?? null) : $entry;
        if (! is_string($version) && ! is_int($version) && ! is_float($version)) {
            return null;
        }
        $version = trim((string) $version);
        if (preg_match('/^[0-9]+(?:\.[0-9]+){0,2}(?:-[a-z0-9._-]+)?$/i', $version) !== 1) {
            return null;
        }

        return $version;
    }

    /**
     * Where the engine puts the image it builds for this runtime, or null; per runtime, as every PHP minor
     * lands in `panelalpha/php`.
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
     * The `Ymd` date stamped into this runtime's built tags, bumped with the recipe so a new image gets built;
     * null when none is declared and the compiled-in date stands.
     */
    public static function recipeDate(string $runtime): ?string
    {
        $declared = self::buildString($runtime, 'recipe', static fn (string $v): bool => preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $v) === 1 && strtotime($v) !== false);

        return $declared === null ? null : str_replace('-', '', $declared);
    }

    /**
     * Is the built image the runtime itself, so a deploy waits for its build? False when unsaid: carrying on is
     * always survivable, blocking on an unasked-for build is not.
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
     * A whitelist of what a registry reference looks like: this value comes from an operator's file and
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
     * A malformed file is the operator's problem to see in their editor, not a deploy's to fail on.
     *
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
