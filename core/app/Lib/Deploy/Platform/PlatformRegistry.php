<?php

namespace App\Lib\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads the manifests in `resources/platforms/` and
 * `resources/apps/<id>/panelalpha.yaml`, highest `priority` first, ties by id.
 * An app config's `extends` overrides the recipe it names; a manifest's
 * `runtime` picks the generator. A malformed file throws on load.
 */
final class PlatformRegistry
{
    /** What an app's manifest is called inside its own directory. */
    public const APP_FILENAME = 'panelalpha.yaml';

    /** @var array<string, list<PlatformManifest>> */
    private static array $cache = [];

    /**
     * Where the shipped manifests live: `core/resources/platforms/`.
     *
     * `realpath()`d when it exists so the cache key and any "not found" message
     * are a path someone can paste; otherwise returned unresolved.
     */
    public static function defaultDirectory(): string
    {
        return self::resolve('platforms');
    }

    /** Where the shipped apps live: `core/resources/apps/`. */
    public static function defaultAppDirectory(): string
    {
        return self::resolve('apps');
    }

    /**
     * Every root a shipped manifest can come from.
     *
     * @return list<string>
     */
    public static function defaultDirectories(): array
    {
        return [self::defaultDirectory(), self::defaultAppDirectory()];
    }

    private static function resolve(string $name): string
    {
        $path = __DIR__ . '/../../../../resources/' . $name;

        return realpath($path) ?: $path;
    }

    /**
     * Every manifest, highest priority first.
     *
     * @return list<PlatformManifest>
     * @throws ManifestException
     */
    public static function all(?string $directory = null): array
    {
        $directories = $directory === null
            ? self::defaultDirectories()
            : [rtrim($directory, '/')];
        $key = implode(':', $directories);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        // Only the first (platforms) root must exist: an engine with no apps
        // still deploys, one with no platforms cannot.
        if (!is_dir($directories[0])) {
            throw new ManifestException("Platform manifest directory not found: {$directories[0]}");
        }

        $manifests = [];
        $seen = [];
        foreach (self::manifestFiles($directories) as $path) {
            $manifest = self::load($path);
            if (isset($seen[$manifest->id])) {
                throw new ManifestException(
                    "Duplicate platform id '{$manifest->id}' in {$path} and {$seen[$manifest->id]}"
                );
            }
            $seen[$manifest->id] = $path;
            $manifests[] = $manifest;
        }

        usort(
            $manifests,
            static fn (PlatformManifest $a, PlatformManifest $b): int => $b->priority <=> $a->priority
                ?: strcmp($a->id, $b->id)
        );

        return self::$cache[$key] = $manifests;
    }

    /**
     * @throws ManifestException
     */
    public static function load(string $path): PlatformManifest
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            throw new ManifestException("Unreadable or empty platform manifest: {$path}");
        }

        try {
            $decoded = Yaml::parse($raw);
        } catch (ParseException $e) {
            throw new ManifestException(
                "Invalid YAML in platform manifest {$path}: " . $e->getMessage(),
                0,
                $e
            );
        }

        if (!is_array($decoded)) {
            throw new ManifestException("Platform manifest {$path} does not contain a mapping");
        }

        return PlatformManifest::fromArray($decoded, self::sourceName($path));
    }

    /**
     * How a manifest names itself in an error message: its path below
     * `resources/`, because thirty of them are called `panelalpha.yaml`.
     */
    private static function sourceName(string $path): string
    {
        $normalised = str_replace('\\', '/', $path);
        $marker = '/resources/';
        $at = strrpos($normalised, $marker);
        if ($at !== false) {
            return substr($normalised, $at + strlen($marker));
        }

        // Outside `resources/` (a fixture, an operator's directory), the last
        // two path segments.
        $parts = explode('/', $normalised);

        return implode('/', array_slice($parts, -2));
    }

    /**
     * The manifest producing a strategy id. Several share one strategy
     * (astro static vs Node), so `$runtime` disambiguates; without it the
     * highest-priority match wins.
     */
    public static function findByStrategy(
        string $strategy,
        ?string $runtime = null,
        ?string $directory = null
    ): ?PlatformManifest {
        $fallback = null;
        foreach (self::all($directory) as $manifest) {
            if ($manifest->strategy !== $strategy) {
                continue;
            }
            if ($runtime !== null && $manifest->runtime === $runtime) {
                return $manifest;
            }
            $fallback ??= $manifest;
        }

        return $fallback;
    }

    /**
     * The manifest with this id. The source recipes are consulted last, and
     * only when `$directory` is null: their ids reach persisted decisions,
     * and everything downstream resolves through here.
     */
    public static function find(string $id, ?string $directory = null): ?PlatformManifest
    {
        foreach (self::all($directory) as $manifest) {
            if ($manifest->id === $id) {
                return $manifest;
            }
        }

        return $directory === null ? SourceRecipes::findById($id) : null;
    }

    /**
     * The manifest a persisted detect decision came from, or null — Railpack
     * and the fallback decisions name no manifest.
     *
     * @param array<string, mixed> $decision
     */
    public static function forDecision(array $decision, ?string $directory = null): ?PlatformManifest
    {
        $id = $decision['platform'] ?? null;

        return is_string($id) && $id !== '' ? self::find($id, $directory) : null;
    }

    /**
     * forDecision(), else the manifest the project's own app config describes
     * when detection chose it: a repository's `.panelalpha/panelalpha.yaml`
     * with an `id:` of its own is registered nowhere, so the id alone finds
     * nothing and its install, upgrade and start commands were dropped.
     *
     * @param array<string, mixed> $decision
     */
    public static function forDecisionOrAppConfig(array $decision, ?AppConfig $appConfig): ?PlatformManifest
    {
        $manifest = self::forDecision($decision);
        if ($manifest !== null || $appConfig === null) {
            return $manifest;
        }
        try {
            $own = SourceRecipes::fromAppConfig($appConfig, 'project');
        } catch (ManifestException) {
            return null;
        }

        return $own !== null && $own->id === ($decision['platform'] ?? null) ? $own : null;
    }

    /** Drop the cache. Tests that write manifests to a temp dir need this. */
    public static function flush(): void
    {
        self::$cache = [];
        SourceRecipes::flush();
    }

    /**
     * Every manifest under the given roots: the YAML files directly in one,
     * and `<subdir>/panelalpha.yaml` one level down. One level, not recursive.
     *
     * `_`- and `.`-prefixed entries are supporting material — the JSON Schema
     * the editor modeline points at, shared fragments — not platforms.
     *
     * @param list<string> $directories
     * @return list<string>
     */
    private static function manifestFiles(array $directories): array
    {
        $files = [];
        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach (scandir($directory) ?: [] as $entry) {
                if (str_starts_with($entry, '_') || str_starts_with($entry, '.')) {
                    continue;
                }
                $path = $directory . '/' . $entry;
                if (is_file($path) && self::isManifestName($entry)) {
                    $files[] = $path;
                    continue;
                }
                $nested = $path . '/' . self::APP_FILENAME;
                if (is_dir($path) && is_file($nested)) {
                    $files[] = $nested;
                }
            }
        }
        sort($files);

        return $files;
    }

    private static function isManifestName(string $entry): bool
    {
        $lower = strtolower($entry);

        return str_ends_with($lower, '.yaml') || str_ends_with($lower, '.yml');
    }
}
