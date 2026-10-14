<?php

namespace App\Lib\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory;
use App\Lib\Deploy\Platform\AppConfig\AppConfigSource;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Source\RepoUrl;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The engine's per-repository directories: `resources/sources/<host>/<owner>/<repo>/`,
 * shaped exactly like a project's own `.panelalpha/` and found by the URL the
 * project was cloned from rather than by reading the checkout.
 *
 * This class answers only the recipe half — the `platform:` block in that
 * directory's `panelalpha.yaml`, turned into a manifest. The scripts, compose
 * file and file snippets beside it are read as an app config by
 * {@see AppConfigDirectory}, through the same locator a repository's own
 * directory goes through.
 */
final class SourceRecipes
{
    /** @var array<string, ?PlatformManifest> directory:slug => manifest */
    private static array $cache = [];

    /**
     * root => the walk's signature and what it found. The signature is checked
     * on every call, so a recipe added to a running worker is seen.
     *
     * @var array<string, array{signature: string, manifests: list<PlatformManifest>}>
     */
    private static array $allCache = [];

    /**
     * Directories {@see all()} walked past and why, keyed by root then slug.
     *
     * @var array<string, array<string, string>>
     */
    private static array $skipped = [];

    /** Where the shipped directories live: `core/resources/sources/`. */
    public static function defaultDirectory(): string
    {
        $path = __DIR__ . '/../../../../resources/sources';

        return realpath($path) ?: $path;
    }

    /**
     * The recipe for the repository this URL names, or null when there is no
     * directory for it or the directory declares no platform. Cached per recipe
     * file stamp, so a long-lived worker sees an added or edited recipe.
     *
     * @throws ManifestException
     */
    public static function for(?string $gitUrl, ?string $directory = null): ?PlatformManifest
    {
        $slug = self::slugOf($gitUrl);
        if ($slug === null) {
            return null;
        }

        $root = self::root($directory);
        $key = $root . ':' . $slug . ':' . self::stamp($root . '/' . $slug);
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        return self::$cache[$key] = self::at($root . '/' . $slug, $slug);
    }

    /**
     * The directory for the repository this URL names, or null when there is none:
     * the lookup {@see for()} does, for callers that need the recipe's checks beside it.
     */
    public static function directoryFor(?string $gitUrl, ?string $directory = null): ?string
    {
        $slug = self::slugOf($gitUrl);
        if ($slug === null) {
            return null;
        }

        $path = self::root($directory) . '/' . $slug;

        return is_dir($path) ? $path : null;
    }

    private static function slugOf(?string $gitUrl): ?string
    {
        if ($gitUrl === null || trim($gitUrl) === '') {
            return null;
        }

        return RepoUrl::slug($gitUrl);
    }

    /** The recipe tree's root, without a trailing slash. */
    private static function root(?string $directory): string
    {
        return rtrim($directory ?? self::defaultDirectory(), '/');
    }

    /**
     * The recipe a `.panelalpha/`-shaped directory declares, wherever it is.
     * Null when there is no directory or it declares no `platform:`, which most do
     * not — but an existing directory without `panelalpha.yaml` is a half-written recipe.
     * The recipe's own `checks/` are passed along, so a `check:` naming one is known.
     *
     * @throws ManifestException
     */
    public static function at(string $dir, ?string $name = null, ?AppConfigSource $source = null): ?PlatformManifest
    {
        $source ??= new LocalAppConfigSource();
        if (!$source->isDirectory(rtrim($dir, '/'))) {
            return null;
        }
        $config = AppConfigDirectory::config($source, $dir);
        if ($config === null) {
            throw new ManifestException(
                ($name ?? $dir) . ': ' . AppConfigDirectory::CONFIG . ' is required'
            );
        }

        return self::fromAppConfig(
            AppConfig::fromYaml($config),
            $name ?? $dir,
            self::checksDirectory($dir)
        );
    }

    /**
     * Where a recipe directory keeps its own health checks, if it keeps any.
     *
     * `checks/` beside `panelalpha.yaml`, and only when it exists: a recipe
     * with no checks of its own must reach `CheckRegistry` as "no directory".
     */
    public static function checksDirectory(string $dir): ?string
    {
        $path = rtrim($dir, '/') . '/checks';

        return is_dir($path) ? $path : null;
    }

    /**
     * The manifest an app config describes, if it describes one.
     *
     * `extends` names the recipe this application is an instance of; every
     * other manifest key overrides that recipe, top level by top level. An id
     * no shipped recipe answers to means the file is the whole manifest.
     *
     * @throws ManifestException
     */
    public static function fromAppConfig(
        ?AppConfig $appConfig,
        string $name,
        ?string $checksDirectory = null
    ): ?PlatformManifest {
        $declared = $appConfig?->manifest();
        if ($declared === null) {
            return null;
        }

        if (isset($declared['detect'])) {
            throw new ManifestException(
                "{$name}: 'detect' has no meaning here — this recipe is found by its path, not by detection"
            );
        }

        $raw = self::mergedOntoBase($declared, $name);
        self::assertKnownStrategy($raw, $name);

        return PlatformManifest::fromArray(
            $raw,
            $name,
            requireDetect: false,
            recipeChecks: $checksDirectory
        );
    }

    /**
     * The declared keys over the recipe they extend. A key with no value does not
     * unset an inherited one, and `extends` itself is not a manifest key.
     *
     * @param array<string, mixed> $declared
     * @return array<string, mixed>
     * @throws ManifestException
     */
    private static function mergedOntoBase(array $declared, string $name): array
    {
        $baseId = self::baseIdOf($declared);
        $base = self::base($baseId);
        if ($base === [] && !isset($declared['label'])) {
            throw new ManifestException("{$name}: nothing extends '{$baseId}' and the file describes no manifest of its own");
        }

        $stated = array_filter($declared, static fn (mixed $value): bool => $value !== null);
        $raw = array_merge(self::withImplicitStrategy($base, $baseId), $stated);
        unset($raw['detect']);
        unset($raw[AppConfig::EXTENDS_KEY]);
        $raw['priority'] ??= 0;

        return $raw;
    }

    /**
     * The recipe to inherit from: `extends` when it is set, else the recipe's own id,
     * so a recipe sharing a shipped manifest's id extends it.
     *
     * @param array<string, mixed> $declared
     */
    private static function baseIdOf(array $declared): string
    {
        $extends = $declared[AppConfig::EXTENDS_KEY] ?? null;

        return is_string($extends) && trim($extends) !== '' ? trim($extends) : (string) $declared['id'];
    }

    /**
     * A base that spells no `strategy` means its id is its strategy (`compose.yaml`
     * produces `compose`); stated, so a recipe with its own id does not default to that.
     *
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private static function withImplicitStrategy(array $base, string $baseId): array
    {
        if (!isset($base['strategy']) && ($base['id'] ?? null) === $baseId) {
            $base['strategy'] = $baseId;
        }

        return $base;
    }

    /**
     * The pipeline falls back on a strategy it does not know, so a typo would deploy
     * green and serve the placeholder page. An unset key keeps the default, the id.
     *
     * @param array<string, mixed> $raw
     * @throws ManifestException
     */
    private static function assertKnownStrategy(array $raw, string $name): void
    {
        if (isset($raw['strategy']) && is_string($raw['strategy']) && !Strategies::isKnown($raw['strategy'])) {
            throw new ManifestException(
                "{$name}: strategy '{$raw['strategy']}' is not one this engine deploys; "
                . 'expected one of: ' . implode(', ', Strategies::ALL)
            );
        }
    }

    /**
     * The source recipe with this id, so a persisted decision resolves back to
     * the manifest it came from.
     *
     * @throws ManifestException
     */
    public static function findById(string $id, ?string $directory = null): ?PlatformManifest
    {
        if ($id === '') {
            return null;
        }
        foreach (self::all($directory) as $manifest) {
            if ($manifest->id === $id) {
                return $manifest;
            }
        }

        return null;
    }

    /**
     * Every recipe the tree declares, in path order. Only id lookups and the
     * contract tests walk it; a deploy reads one directory. Several recipes may
     * share an id; the first in path order answers for it.
     *
     * Cached until a recipe file changes, so a long-lived worker sees new recipes.
     * A directory it cannot read is skipped and recorded in {@see skipped()},
     * not thrown, so one malformed recipe does not break every lookup.
     *
     * @return list<PlatformManifest>
     */
    public static function all(?string $directory = null): array
    {
        $root = self::root($directory);
        $directories = self::directories($root);
        $signature = self::signature($directories);
        if (isset(self::$allCache[$root]) && self::$allCache[$root]['signature'] === $signature) {
            return self::$allCache[$root]['manifests'];
        }

        $manifests = [];
        $skipped = [];
        foreach ($directories as $slug => $dir) {
            $manifest = self::readOrSkip($dir, $slug, $skipped);
            if ($manifest !== null) {
                $manifests[] = $manifest;
            }
        }

        self::$skipped[$root] = $skipped;
        self::$allCache[$root] = ['signature' => $signature, 'manifests' => $manifests];

        return $manifests;
    }

    /**
     * The recipe in one directory of the walk, or null; a malformed one is logged
     * and recorded in $skipped rather than thrown.
     *
     * @param array<string, string> $skipped slug => reason, filled in place
     */
    private static function readOrSkip(string $dir, string $slug, array &$skipped): ?PlatformManifest
    {
        try {
            return self::at($dir, $slug);
        } catch (ManifestException $e) {
            $skipped[$slug] = $e->getMessage();
            error_log('panelalpha: skipping recipe directory ' . $dir . ': ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Which recipe files exist and when each last changed: cheap to take (one
     * stat per recipe) and different whenever a parse would be.
     *
     * @param array<string, string> $directories slug => directory
     */
    private static function signature(array $directories): string
    {
        $parts = [];
        foreach ($directories as $slug => $dir) {
            $parts[] = $slug . '=' . self::stamp($dir);
        }

        return md5(implode("\n", $parts));
    }

    /** The recipe file's mtime and size, or 'none' when it is not there. */
    private static function stamp(string $dir): string
    {
        $file = $dir . '/' . AppConfigDirectory::CONFIG;
        clearstatcache(true, $file);
        $stat = @stat($file);

        return $stat === false ? 'none' : $stat['mtime'] . '.' . $stat['size'];
    }

    /**
     * What the last {@see all()} over this tree could not read, slug => reason.
     * Deploying the malformed app itself still fails, through {@see at()}.
     *
     * @return array<string, string>
     */
    public static function skipped(?string $directory = null): array
    {
        $root = self::root($directory);
        if (!isset(self::$skipped[$root])) {
            self::all($root);
        }

        return self::$skipped[$root] ?? [];
    }

    /**
     * Every recipe directory under the tree, keyed by slug.
     *
     * A recipe lives at least at `<host>/<owner>/<repo>` (three levels) and is
     * any directory there that carries a `panelalpha.yaml`. The owner may be a
     * multi-level GitLab namespace, so the walk descends until it meets the
     * manifest rather than stopping at a fixed depth: a namespace directory
     * above a subgroup repo (`<host>/<group>/<subgroup>/`) is walked through,
     * and the manifest, once found, ends the descent so a recipe's own nested
     * `files/…/panelalpha.yaml` payload is not mistaken for another recipe.
     *
     * A three-level leaf with no manifest is kept — the half-written recipe
     * {@see all()} reports rather than throws.
     *
     * @return array<string, string>
     */
    public static function directories(?string $directory = null): array
    {
        $root = self::root($directory);
        if (!is_dir($root)) {
            return [];
        }

        $found = [];
        foreach (self::entries($root) as $host) {
            self::collect("{$root}/{$host}", $host, 1, $found);
        }
        ksort($found);

        return $found;
    }

    /**
     * Record every recipe directory in one namespace subtree.
     *
     * @param array<string, string> $found slug => directory, filled in place
     */
    private static function collect(string $dir, string $slug, int $depth, array &$found): void
    {
        $children = self::entries($dir);
        if ($depth >= 3 && (is_file($dir . '/' . AppConfigDirectory::CONFIG) || $children === [])) {
            $found[$slug] = $dir;

            return;
        }
        foreach ($children as $child) {
            self::collect("{$dir}/{$child}", "{$slug}/{$child}", $depth + 1, $found);
        }
    }

    /** Drop the cache outright. The cache checks itself; tests still reset it. */
    public static function flush(): void
    {
        self::$cache = [];
        self::$allCache = [];
        self::$skipped = [];
    }

    /** @return list<string> directory names, `_` and `.` entries skipped */
    private static function entries(string $directory): array
    {
        $names = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if (str_starts_with($entry, '.') || str_starts_with($entry, '_')) {
                continue;
            }
            if (is_dir($directory . '/' . $entry)) {
                $names[] = $entry;
            }
        }

        return $names;
    }

    /**
     * The shipped recipe an id names, as raw YAML — the override merge happens
     * before validation, so the result is checked as the one thing it will be.
     * Resolved by path rather than through {@see PlatformRegistry}, which
     * would load every manifest and would recurse back into this class.
     *
     * @return array<string, mixed>
     * @throws ManifestException
     */
    private static function base(string $id): array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $id)) {
            return [];
        }

        $paths = [
            PlatformRegistry::defaultAppDirectory() . '/' . $id . '/' . PlatformRegistry::APP_FILENAME,
            PlatformRegistry::defaultDirectory() . '/' . $id . '.yaml',
            PlatformRegistry::defaultDirectory() . '/' . $id . '.yml',
        ];
        foreach ($paths as $path) {
            if (is_file($path)) {
                return self::decode($path);
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     * @throws ManifestException
     */
    private static function decode(string $path): array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            throw new ManifestException("Unreadable or empty manifest: {$path}");
        }

        try {
            $decoded = Yaml::parse($raw);
        } catch (ParseException $e) {
            throw new ManifestException("Invalid YAML in {$path}: " . $e->getMessage(), 0, $e);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
