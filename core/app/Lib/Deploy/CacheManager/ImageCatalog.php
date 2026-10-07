<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Platform\Runtime\Php\PhpExtensions;
use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;
use Symfony\Component\Yaml\Yaml;

/**
 * The prewarm half of `config/core/images.yaml`: what the host holds before
 * any deploy asks for it.
 *
 * The same file's `runtimes` block is what a project resolves against
 * ({@see RuntimeImageCatalog}); a version there carries a `prewarm` priority
 * when this host should hold it. Reading one file for both is what stops a
 * version being warmed that nothing resolves to, or resolved to that nothing
 * warmed.
 *
 * Two things read it: {@see prewarmed()}, what the host pulls or builds ahead
 * of any deploy, and {@see all()}, everything named here at all, which account
 * teardown must not reclaim as one project's leftovers.
 *
 * It does not say what an account loads. A second file used to, listing images
 * to hand an account before a Railpack build; eight of its eleven entries were
 * never referenced by a Railpack plan and two of the three that were had gone
 * stale against the installed binary. That preload reads the plan railpack
 * writes instead.
 *
 * An entry naming a runtime and a version resolves through
 * {@see RuntimeImageCatalog}, so a PHP minor becomes the shared base image the
 * engine builds and a Go minor the stock tag, without either being spelled
 * out twice.
 *
 * A file that is missing, unparseable or empty yields nothing, and the caller
 * warms and loads nothing rather than guessing.
 */
final class ImageCatalog
{
    private const CONFIG_PATH = __DIR__ . '/../../../../../config/core/images.yaml';

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
     * Every image named here, whatever it is for. What account teardown must
     * not reclaim as if it belonged to one project.
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

        usort($items, static fn (array $a, array $b): int
            => $b['prewarm'] === $a['prewarm']
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
     * One declared entry as something usable, or null when it names nothing
     * this host can resolve — a runtime absent from the image catalogue, a
     * version it does not describe, a ref docker would not accept.
     *
     * @param array<string, mixed> $entry
     * @return array{id: string, ref: string, kind: string, runtime: string, prewarm: ?int, why: string}|null
     */
    private static function resolve(array $entry): ?array
    {
        $runtime = is_string($entry['runtime'] ?? null) ? strtolower(trim($entry['runtime'])) : null;
        if ($runtime === null) {
            // Every entry declares one, literal images included: it is how
            // `--runtimes=php` selects composer:2 along with the PHP bases.
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
            $ref = ImageTransfer::normalizeImageRef($literal);

            return $ref === null ? null : [
                'id' => self::repository($literal),
                'ref' => $ref,
                'kind' => HostPrewarmPlan::KIND_PULL,
            ] + $common;
        }

        $version = $entry['version'] ?? null;
        if (!is_string($version) && !is_int($version) && !is_float($version)) {
            return null;
        }
        $version = trim((string) $version);
        // Only a version the runtimes block declares. spec() substitutes
        // {version} into the template whatever it is handed, so without this a
        // typo names `php:9.9-apache-bookworm` -- a tag nobody published.
        if (!in_array($version, RuntimeImageCatalog::versions($runtime), true)) {
            return null;
        }
        $spec = RuntimeImageCatalog::spec($runtime, $version);
        if ($spec === null) {
            return null;
        }

        // isBuilt() is the catalogue's own answer: strip `image.build` from a
        // runtime and its entry becomes the stock tag.
        $built = $spec->isBuilt() ? self::builtTag($runtime, $spec->from, $entry) : null;
        $ref = $built ?? $spec->from;
        if (!ImageTransfer::isSafeImageRef($ref)) {
            return null;
        }

        $extensions = self::entryExtensions($entry);
        sort($extensions);

        return [
            'id' => $runtime . ':' . $version . ($extensions === [] ? '' : '+' . implode('+', $extensions)),
            'ref' => $ref,
            'kind' => $built === null ? HostPrewarmPlan::KIND_PULL : HostPrewarmPlan::KIND_BUILD,
            // Only a variant needs it -- a plain base's Dockerfile is derivable
            // from its tag -- but the extensions have to survive to the build,
            // and an extension-less entry resolves to the same [] a plain base
            // does.
            'extensions' => $built === null ? [] : self::entryExtensions($entry),
        ] + $common;
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
     * The tag the engine would build for this runtime at this version, or null
     * when there is nothing to build ahead of a deploy.
     *
     * Only PHP has one. Ruby's and Python's bases fingerprint an apt package
     * set that comes from the project's own Gemfile or requirements, so there
     * is no tag to compute from a version alone — the entry is the stock image
     * their base will be built from.
     */
    private static function builtTag(string $runtime, string $upstream, array $entry = []): ?string
    {
        return match ($runtime) {
            // An `extensions:` list on the entry names an extension *variant*
            // (…-pa<date>-x<hash>): the same image plus those extensions in one
            // layer. A variant is normally built by the deploy that needs it,
            // which is why it can never be warmed -- but the engine also knows
            // the sets its own apps ask for, and those belong in the plan
            // instead of in somebody's first deploy.
            'php' => PhpBaseImage::tag($upstream, self::entryExtensions($entry)),
            default => null,
        };
    }

    /**
     * The `extensions:` a catalogue entry declares, or none.
     *
     * Validated by {@see PhpBaseImage::normalizeExtras()}, so a bad name is
     * dropped rather than reaching a shell.
     *
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private static function entryExtensions(array $entry): array
    {
        $extensions = $entry['extensions'] ?? null;
        if (!is_array($extensions)) {
            return [];
        }

        // Reduced the way a deploy reduces it, not merely normalised. Reading a
        // composer.json, three filters stand between `ext-foo` and a variant:
        // PHP's own bundled set (PhpExtensions), the set this base already
        // bakes (missingExtensions), and the MAX_BAKED_EXTRAS ceiling past
        // which there is no variant at all (bakeableExtras). An `extensions:`
        // list arrives from the other direction and has to pass the same three,
        // or it names a variant no deploy can ever compute: `["mongodb", "gd"]`
        // hashes one way here and, because gd is baked, another way there. The
        // host would then build and keep an image nobody asks for while the app
        // that needed mongodb still compiles it on its first deploy -- no
        // error, no warning, just a silently wasted build and a slow deploy.
        return PhpBaseImage::bakeableExtras(
            PhpBaseImage::missingExtensions(PhpExtensions::installable($extensions))
        );
    }

    /**
     * Every declared entry, runtime versions first and then the extras, in the
     * shape {@see resolve()} takes. A runtime version already knows its own
     * runtime and version; an extra states them.
     *
     * @return list<array<string, mixed>>
     */
    private static function declared(): array
    {
        $entries = [];
        $runtimes = self::config()['runtimes'] ?? null;
        foreach (is_array($runtimes) ? $runtimes : [] as $runtime => $spec) {
            if (!is_string($runtime) || !is_array($spec)) {
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
        if (!is_file($path)) {
            return self::$cache;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
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
