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

    /** @var array<string, list<PlatformManifest>> */
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
     * directory for it or the directory declares no platform.
     *
     * @throws ManifestException
     */
    public static function for(?string $gitUrl, ?string $directory = null): ?PlatformManifest
    {
        if ($gitUrl === null || trim($gitUrl) === '') {
            return null;
        }
        $slug = RepoUrl::slug($gitUrl);
        if ($slug === null) {
            return null;
        }

        $root = rtrim($directory ?? self::defaultDirectory(), '/');
        $key = $root . ':' . $slug;
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        return self::$cache[$key] = self::at($root . '/' . $slug, $slug);
    }

    /**
     * The directory for the repository this URL names, or null when there is none.
     *
     * The same lookup {@see for()} does, one step earlier: the health checks a
     * recipe ships live beside its manifest, and a caller that has only the URL
     * — the deploy, which freezes the path onto the account — needs the
     * directory rather than the parsed recipe.
     */
    public static function directoryFor(?string $gitUrl, ?string $directory = null): ?string
    {
        if ($gitUrl === null || trim($gitUrl) === '') {
            return null;
        }
        $slug = RepoUrl::slug($gitUrl);
        if ($slug === null) {
            return null;
        }

        $root = rtrim($directory ?? self::defaultDirectory(), '/');
        $path = $root . '/' . $slug;

        return is_dir($path) ? $path : null;
    }

    /**
     * The recipe a `.panelalpha/`-shaped directory declares, wherever it is.
     * Null when it declares no `platform:`, which most do not — but the
     * `panelalpha.yaml` itself has to be there.
     *
     * @throws ManifestException
     */
    public static function at(string $dir, ?string $name = null, ?AppConfigSource $source = null): ?PlatformManifest
    {
        $source ??= new LocalAppConfigSource();
        // No directory is the ordinary case: nobody has written about this
        // repository. An empty one is a half-written recipe.
        if (!$source->isDirectory(rtrim($dir, '/'))) {
            return null;
        }
        $config = AppConfigDirectory::config($source, $dir);
        if ($config === null) {
            throw new ManifestException(
                ($name ?? $dir) . ': ' . AppConfigDirectory::CONFIG . ' is required'
            );
        }

        // The recipe's own check files, so a `check:` entry naming one is not
        // refused as unknown. Passed as the directory for the same reason the
        // health check keeps it: the checks live here, beside the manifest that
        // needs them.
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
     * with no checks of its own must reach `CheckRegistry` as "no directory",
     * so the answer is the same as it was before this existed.
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

        $id = $declared['id'];
        // `extends`, when present, names the recipe to inherit from and the id
        // does not: the two split in AppConfig so a source recipe can be its
        // own thing (its own id, resolvable by itself) that starts from a
        // shipped recipe. When `extends` is absent the id itself is the base
        // — a recipe sharing a shipped manifest's id extends it, which is how
        // `extends: matomo` reads before this split.
        $baseId = is_string($raw = $declared[AppConfig::EXTENDS_KEY] ?? null)
            && trim($raw) !== '' ? trim($raw) : (string) $id;
        $base = self::base($baseId);
        if ($base === [] && !isset($declared['label'])) {
            throw new ManifestException("{$name}: nothing extends '{$baseId}' and the file describes no manifest of its own");
        }

        // A base that spells no `strategy` means its id *is* its strategy —
        // `compose.yaml` carries no `strategy:` key, `compose` is the strategy
        // it produces. A recipe that states its own id and inherits such a base
        // therefore inherits no strategy at all, and `PlatformManifest` then
        // defaults it to the recipe's own id: `extends: compose` + `id:
        // market-radar` produced `strategy: market-radar`, which no generator
        // recognises. State the base's id as the strategy, which is exactly
        // what the base itself meant by omitting the key.
        if (!isset($base['strategy']) && ($base['id'] ?? null) === $baseId) {
            $base['strategy'] = $baseId;
        }

        // A key written with no value says nothing; it does not unset what the
        // recipe being extended declared. Removing an inherited value means
        // writing the manifest out rather than extending one.
        $stated = array_filter($declared, static fn (mixed $value): bool => $value !== null);

        $raw = array_merge($base, $stated);
        unset($raw['detect']);
        // `extends` was the address of the base recipe, not a manifest key —
        // PlatformManifest would refuse it. Drop it once the base is merged.
        unset($raw[AppConfig::EXTENDS_KEY]);
        $raw['priority'] ??= 0;

        return PlatformManifest::fromArray(
            $raw,
            $name,
            requireDetect: false,
            recipeChecks: $checksDirectory
        );
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
     * contract tests walk it; a deploy reads one directory.
     *
     * A directory it cannot read is skipped and recorded in {@see skipped()},
     * not thrown: this walk stands behind findById(), so one malformed
     * directory used to take every app on the host with it.
     *
     * @return list<PlatformManifest>
     */
    public static function all(?string $directory = null): array
    {
        $root = rtrim($directory ?? self::defaultDirectory(), '/');
        if (isset(self::$allCache[$root])) {
            return self::$allCache[$root];
        }

        $manifests = [];
        $seen = [];
        $skipped = [];
        foreach (self::directories($root) as $slug => $dir) {
            // A directory this cannot read breaks its own app, not the
            // registry. `at()` throwing is right when a caller named that
            // repository -- silence there would hide a typo -- but `all()` is
            // walked by findById(), so every deploy and every health report on
            // the host went down with one malformed directory, naming an
            // application the reader had never heard of. Twice in one day:
            // once for a write-up committed without a manifest, once for the
            // window between `mkdir` and writing panelalpha.yaml while a
            // recipe was being written.
            try {
                $manifest = self::at($dir, $slug);
            } catch (ManifestException $e) {
                $skipped[$slug] = $e->getMessage();
                error_log('panelalpha: skipping recipe directory ' . $dir . ': ' . $e->getMessage());
                continue;
            }
            if ($manifest === null) {
                continue;
            }
            // Several repositories may deliberately share one recipe id —
            // `ladigitale-spa-php` is the point of the per-app-id + extends
            // split: N family members that are one stack. The first directory
            // in path order answers for the id (a deploy records the id and
            // everything downstream resolves it through PlatformRegistry,
            // whose `find` walks the shipped manifests before these, so the
            // lookup lands on whichever of the same-stack recipes is first);
            // a later one is still reachable by its own path.
            $seen[$manifest->id] ??= $slug;
            $manifests[] = $manifest;
        }

        self::$skipped[$root] = $skipped;

        return self::$allCache[$root] = $manifests;
    }

    /**
     * What the last {@see all()} over this tree could not read, slug => reason.
     *
     * Skipping without saying so would turn a loud failure into a silent one,
     * which is the other way to get this wrong. Deploying the malformed app
     * itself still fails, through {@see at()} on its own path.
     *
     * @return array<string, string>
     */
    public static function skipped(?string $directory = null): array
    {
        $root = rtrim($directory ?? self::defaultDirectory(), '/');
        if (!isset(self::$skipped[$root])) {
            self::all($root);
        }

        return self::$skipped[$root] ?? [];
    }

    /**
     * Every `<host>/<owner>/<repo>` directory under the tree, keyed by slug.
     *
     * Exactly three levels: the depth is the address, so anything at another
     * depth is a mistake rather than a repository.
     *
     * @return array<string, string>
     */
    public static function directories(?string $directory = null): array
    {
        $root = rtrim($directory ?? self::defaultDirectory(), '/');
        if (!is_dir($root)) {
            return [];
        }

        $found = [];
        foreach (self::entries($root) as $host) {
            foreach (self::entries("{$root}/{$host}") as $owner) {
                foreach (self::entries("{$root}/{$host}/{$owner}") as $repo) {
                    $found["{$host}/{$owner}/{$repo}"] = "{$root}/{$host}/{$owner}/{$repo}";
                }
            }
        }
        ksort($found);

        return $found;
    }

    /** Drop the cache. Tests that write directories to a temp dir need this. */
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
