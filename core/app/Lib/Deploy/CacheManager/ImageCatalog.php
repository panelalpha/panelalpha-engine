<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Platform\Runtime\Php\PhpExtensions;
use App\Lib\Deploy\Platform\Runtime\Python\SystemPackages as PythonSystemPackages;
use App\Lib\Deploy\Platform\Runtime\Ruby\SystemPackages as RubySystemPackages;
use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;
use Symfony\Component\Yaml\Yaml;

/**
 * The prewarm half of `config/core/images.yaml` ({@see RuntimeImageCatalog} reads its `runtimes`): what the
 * host holds before any deploy asks for it. A missing, unparseable or empty file yields nothing.
 */
final class ImageCatalog
{
    private const CONFIG_PATH = __DIR__.'/../../../../../config/core/images.yaml';

    private static ?string $configPath = null;

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /**
     * Every entry the file describes, in declared order.
     *
     * @return list<array{id: string, ref: string, kind: string, runtime: string, prewarm: ?int, why: string}>
     */
    public static function entries(): array
    {
        $entries = [];
        $seen = [];
        foreach (self::declared() as $entry) {
            $resolved = self::resolve($entry);
            if ($resolved === null || isset($seen[$resolved['ref']])) {
                continue;
            }
            $seen[$resolved['ref']] = true;
            $entries[] = $resolved;
        }

        return $entries;
    }

    /**
     * Every image named here, whatever it is for: what account teardown must not reclaim.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(static fn (array $e): string => $e['ref'], self::entries());
    }

    /**
     * What the host warms, highest priority first; ties broken by ref so a run
     * is reproducible. An entry with no `prewarm` is not warmed at all.
     *
     * @return list<array{id: string, ref: string, kind: string, runtime: string, prewarm: ?int, why: string}>
     */
    public static function prewarmed(): array
    {
        $items = array_values(array_filter(
            self::entries(),
            static fn (array $e): bool => $e['prewarm'] !== null
        ));

        usort($items, static fn (array $a, array $b): int => $b['prewarm'] === $a['prewarm']
                ? strcmp($a['ref'], $b['ref'])
                : $b['prewarm'] <=> $a['prewarm']);

        return $items;
    }

    /** Raw `reserve`, for the caller to parse. Null when the file omits it. */
    public static function reserve(): ?string
    {
        return self::scalar('reserve');
    }

    /** Raw `budget`; the literal "none" means no limit but the reserve. */
    public static function budget(): ?string
    {
        return self::scalar('budget');
    }

    /** Tests point this at a fixture; null restores the shipped file. */
    public static function useConfig(?string $path): void
    {
        self::$configPath = $path;
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * One declared entry as something usable, or null when it names nothing this host can resolve.
     * Every entry declares a runtime, literal images included: it is how `--runtimes=php` selects composer:2.
     *
     * @param  array<string, mixed>  $entry
     * @return array{id: string, ref: string, kind: string, runtime: string, prewarm: ?int, why: string}|null
     */
    private static function resolve(array $entry): ?array
    {
        $runtime = is_string($entry['runtime'] ?? null) ? strtolower(trim($entry['runtime'])) : null;
        if ($runtime === null) {
            return null;
        }
        $prewarm = $entry['prewarm'] ?? null;
        $common = [
            'runtime' => $runtime,
            'prewarm' => is_int($prewarm) ? $prewarm : null,
            'why' => is_string($entry['why'] ?? null) ? $entry['why'] : '',
        ];

        $literal = $entry['image'] ?? null;
        if (is_string($literal) && $literal !== '') {
            return self::resolveLiteral($literal, $common);
        }

        return self::resolveVersion($entry, $runtime, $common);
    }

    /**
     * @param  array{runtime: string, prewarm: ?int, why: string}  $common
     * @return array{id: string, ref: string, kind: string, runtime: string, prewarm: ?int, why: string}|null
     */
    private static function resolveLiteral(string $literal, array $common): ?array
    {
        $ref = ImageTransfer::normalizeImageRef($literal);

        return $ref === null ? null : [
            'id' => self::repository($literal),
            'ref' => $ref,
            'kind' => HostPrewarmPlan::KIND_PULL,
        ] + $common;
    }

    /**
     * Without `image.build` the entry is the stock tag; only a variant needs `extensions` or `packages`, but they
     * have to survive to the build, and a plain entry resolves to the [] a plain base does.
     *
     * @param  array<string, mixed>  $entry
     * @param  array{runtime: string, prewarm: ?int, why: string}  $common
     * @return array{id: string, ref: string, kind: string, runtime: string, prewarm: ?int, why: string}|null
     */
    private static function resolveVersion(array $entry, string $runtime, array $common): ?array
    {
        $version = self::declaredVersion($entry, $runtime);
        if ($version === null) {
            return null;
        }
        $spec = RuntimeImageCatalog::spec($runtime, $version);
        if ($spec === null) {
            return null;
        }

        $extensions = $runtime === 'php' ? self::entryExtensions($entry) : [];
        $packages = self::entryPackages($runtime, $entry);
        $built = $spec->isBuilt() ? self::builtTag($runtime, $spec->from, $extensions, $packages) : null;
        $ref = $built ?? $spec->from;
        if (! ImageTransfer::isSafeImageRef($ref)) {
            return null;
        }
        $named = [...$extensions, ...self::declaredPackages($entry)];
        sort($named);

        return [
            'id' => $runtime.':'.$version.($named === [] ? '' : '+'.implode('+', array_unique($named))),
            'ref' => $ref,
            'kind' => $built === null ? HostPrewarmPlan::KIND_PULL : HostPrewarmPlan::KIND_BUILD,
            'extensions' => $built === null ? [] : $extensions,
            'packages' => $built === null ? [] : $packages,
        ] + $common;
    }

    /**
     * Only a version the runtimes block declares: spec() substitutes any {version}, so a typo
     * would name `php:9.9-apache-bookworm`, a tag nobody published.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function declaredVersion(array $entry, string $runtime): ?string
    {
        $version = $entry['version'] ?? null;
        if (! is_string($version) && ! is_int($version) && ! is_float($version)) {
            return null;
        }
        $version = trim((string) $version);
        if (! in_array($version, RuntimeImageCatalog::versions($runtime), true)) {
            return null;
        }

        return $version;
    }

    /** An extra's id: its image without the tag, which moves with upstream releases. */
    private static function repository(string $image): string
    {
        $image = trim(explode('@', trim($image), 2)[0]);
        $slash = strrpos($image, '/');
        $colon = strrpos($image, ':');

        return $colon !== false && ($slash === false || $colon > $slash) ? substr($image, 0, $colon) : $image;
    }

    /**
     * The tag the engine would build ahead of a deploy. A plain PHP entry is the base every PHP app runs on; a Python
     * or Ruby base depends on the project's packages, so only an entry naming `packages:` has one.
     *
     * @param  list<string>  $extensions
     * @param  list<string>  $packages
     */
    private static function builtTag(string $runtime, string $upstream, array $extensions, array $packages): ?string
    {
        return match ($runtime) {
            'php' => PhpBaseImage::tag($upstream, $extensions, $packages),
            'python' => $packages === [] ? null : PythonBaseImage::tag($upstream, $packages),
            'ruby' => $packages === [] ? null : RubyBaseImage::tag($upstream, $packages),
            default => null,
        };
    }

    /**
     * The packages the build installs, as a deploy computes them: PHP's own system-package filter, or the
     * runtime's always-installed set plus what the entry names, since a Python or Ruby deploy always adds it.
     *
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private static function entryPackages(string $runtime, array $entry): array
    {
        $declared = self::declaredPackages($entry);
        if ($declared === []) {
            return [];
        }

        return match ($runtime) {
            'php' => PhpBaseImage::normalizeSystemPackages($declared),
            'python' => [...PythonSystemPackages::always(), ...$declared],
            'ruby' => [...RubySystemPackages::always(), ...$declared],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private static function declaredPackages(array $entry): array
    {
        $packages = $entry['packages'] ?? null;

        return is_array($packages)
            ? array_values(array_filter(array_map(static fn (mixed $p): string => is_string($p) ? strtolower(trim($p)) : '', $packages)))
            : [];
    }

    /**
     * The `extensions:` a catalogue entry declares, reduced through the same three filters a deploy
     * applies, or it names a variant no deploy computes and the host builds an image nobody asks for.
     *
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private static function entryExtensions(array $entry): array
    {
        $extensions = $entry['extensions'] ?? null;
        if (! is_array($extensions)) {
            return [];
        }

        return PhpBaseImage::bakeableExtras(
            PhpBaseImage::missingExtensions(PhpExtensions::installable($extensions))
        );
    }

    /**
     * Every declared entry, runtime versions first and then the extras, in the shape {@see resolve()} takes.
     *
     * @return list<array<string, mixed>>
     */
    private static function declared(): array
    {
        $entries = [];
        $runtimes = self::config()['runtimes'] ?? null;
        foreach (is_array($runtimes) ? $runtimes : [] as $runtime => $spec) {
            if (! is_string($runtime) || ! is_array($spec)) {
                continue;
            }
            foreach (is_array($spec['versions'] ?? null) ? $spec['versions'] : [] as $version) {
                if (is_array($version)) {
                    $entries[] = $version + ['runtime' => $runtime];
                } elseif (is_string($version) || is_int($version) || is_float($version)) {
                    $entries[] = ['runtime' => $runtime, 'version' => $version];
                }
            }
        }

        $extras = self::config()['extras'] ?? null;
        foreach (is_array($extras) ? $extras : [] as $extra) {
            if (is_array($extra)) {
                $entries[] = $extra;
            }
        }

        return $entries;
    }

    private static function scalar(string $key): ?string
    {
        $host = self::config()['host'] ?? null;
        $value = is_array($host) ? ($host[$key] ?? null) : null;

        return is_string($value) || is_int($value) ? trim((string) $value) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];

        $path = self::$configPath ?? self::CONFIG_PATH;
        if (! is_file($path)) {
            return self::$cache;
        }
        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return self::$cache;
        }
        try {
            $parsed = Yaml::parse($raw);
        } catch (\Exception $e) {
            return self::$cache;
        }
        if (is_array($parsed)) {
            self::$cache = $parsed;
        }

        return self::$cache;
    }
}
