<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Dind\DindAccountCleanup;
use App\Lib\Deploy\Dind\DindImageStore;

/**
 * Which host images the daily prune may remove: only what the deploy path puts on the host, never
 * the engine's own images or compose sidecars ({@see DindAccountCleanup}); "cannot judge" means keep.
 */
final class HostImageRetention
{
    public const DEFAULT_MAX_AGE = '3d';

    public const DEFAULT_BUILD_CACHE_MAX_AGE = '24h';

    /** The name an image goes by, without the loopback registry alias the load path tags. */
    public static function logicalRef(string $tag): string
    {
        $alias = DindImageStore::HOST_CACHE_REGISTRY.'/';

        return str_starts_with($tag, $alias) ? substr($tag, strlen($alias)) : $tag;
    }

    /** `golang` for `golang:1.22-alpine`, `127.0.0.1:5000/a/b` for `127.0.0.1:5000/a/b:t`. */
    public static function repository(string $ref): string
    {
        $slash = strrpos($ref, '/');
        $colon = strrpos($ref, ':');

        return $colon !== false && ($slash === false || $colon > $slash) ? substr($ref, 0, $colon) : $ref;
    }

    /**
     * @param  list<string>  $catalog  every ref images.yaml names ({@see ImageCatalog::all()})
     */
    public static function inScope(string $ref, array $catalog): bool
    {
        if (BuiltImage::isOurs($ref) || self::isBuildImage($ref) || RailpackCache::isRailpackImage($ref)) {
            return true;
        }
        $repository = self::repository($ref);
        foreach ($catalog as $named) {
            if (self::repository($named) === $repository) {
                return true;
            }
        }

        return false;
    }

    /** A host build image ({@see NodeBuildImage}, {@see RustBuildToolsImage}): host only, never pushed. */
    public static function isBuildImage(string $ref): bool
    {
        return in_array(self::repository($ref), [NodeBuildImage::REPOSITORY, RustBuildToolsImage::REPOSITORY], true)
            && preg_match('/:[^:\/]+-pa[0-9a-f]+$/i', $ref) === 1;
    }

    /**
     * What a newer build of the same kind replaces, e.g. `panelalpha/python:3.12-slim` for every
     * package set on python:3.12-slim. Null outside the images the engine builds.
     */
    public static function family(string $ref): ?string
    {
        $parts = BuiltImage::parts($ref);
        if ($parts !== null) {
            return $parts['repository'].':'.$parts['upstreamTag'].($parts['variant'] === null ? '' : '-x');
        }

        return self::isBuildImage($ref) ? (string) preg_replace('/-pa[0-9a-f]+$/i', '', $ref) : null;
    }

    /**
     * Built from a recipe older than the current one (`…-pa20260922` once the
     * recipe is 20261007): no deploy asks for that tag again.
     */
    public static function isSuperseded(string $ref): bool
    {
        $parts = BuiltImage::parts($ref);
        $current = $parts === null ? null : BuiltImage::currentFingerprint($parts['runtime']);

        return $current !== null && strcasecmp($parts['fingerprint'], $current) !== 0;
    }

    /**
     * Images old enough and unprotected enough to remove, before the deploy
     * logs have had their say.
     *
     * @param  list<array{tag: string, bytes: ?int, since: ?int}>  $images  one row
     *                                                                      per host tag; since = LastTagTime as a unix time, null when unknown
     * @param  list<string>  $prewarmed  the prewarm catalogue's refs
     * @param  list<string>  $catalog  every ref images.yaml names
     * @param  list<string>  $containerRefs  images host containers were started from
     * @return array<string, array{tags: list<string>, bytes: ?int, since: int}> keyed by logical ref
     */
    public static function candidates(
        array $images,
        array $prewarmed,
        array $catalog,
        array $containerRefs,
        int $now,
        int $maxAge
    ): array {
        $protected = array_flip(array_merge($prewarmed, array_map(self::logicalRef(...), $containerRefs)));

        $groups = self::groupedByRef($images);
        $newest = self::newestOfEachFamily($groups);

        $candidates = [];
        foreach ($groups as $ref => $group) {
            if (isset($protected[$ref]) || ! self::inScope($ref, $catalog)) {
                continue;
            }
            if (! $group['known'] || $group['since'] === null) {
                continue;
            }
            if (! self::isSuperseded($ref)) {
                $family = self::family($ref);
                if ($group['since'] > $now - $maxAge || ($family !== null && $newest[$family][1] === $ref)) {
                    continue;
                }
            }
            $candidates[$ref] = ['tags' => $group['tags'], 'bytes' => $group['bytes'], 'since' => $group['since']];
        }

        return $candidates;
    }

    /**
     * The newest tag decides a group's age, and one unknown age makes the whole group unknown.
     *
     * @param  list<array{tag: string, bytes: ?int, since: ?int}>  $images
     * @return array<string, array{tags: list<string>, bytes: ?int, since: ?int, known: bool}>
     */
    private static function groupedByRef(array $images): array
    {
        $groups = [];
        foreach ($images as $image) {
            $tag = $image['tag'];
            if ($tag === '' || str_contains($tag, '<none>') || ! ImageTransfer::isSafeImageRef($tag)) {
                continue;
            }
            $ref = self::logicalRef($tag);
            $group = $groups[$ref] ?? ['tags' => [], 'bytes' => null, 'since' => null, 'known' => true];
            $group['tags'][] = $tag;
            $group['bytes'] = $image['bytes'] ?? $group['bytes'];
            if ($image['since'] === null) {
                $group['known'] = false;
            } else {
                $group['since'] = max($group['since'] ?? 0, $image['since']);
            }
            $groups[$ref] = $group;
        }

        return $groups;
    }

    /**
     * Each family's newest image, by age and then name, so a tie keeps one.
     *
     * @param  array<string, array{tags: list<string>, bytes: ?int, since: ?int, known: bool}>  $groups
     * @return array<string, array{0: int, 1: string}>
     */
    private static function newestOfEachFamily(array $groups): array
    {
        $newest = [];
        foreach ($groups as $ref => $group) {
            $family = self::family($ref);
            if ($family === null || ! $group['known'] || $group['since'] === null || self::isSuperseded($ref)) {
                continue;
            }
            $key = [$group['since'], $ref];
            if (! isset($newest[$family]) || $key > $newest[$family]) {
                $newest[$family] = $key;
            }
        }

        return $newest;
    }

    /**
     * Which of $refs a deploy log names, in both JSON slash spellings; a ref must end where the name
     * ends, so `…-pa20260922` is not named by `…-pa20260922-x1234`.
     *
     * @param  list<string>  $refs
     * @return list<string>
     */
    public static function mentionedIn(string $log, array $refs): array
    {
        $found = [];
        foreach ($refs as $ref) {
            foreach (array_unique([$ref, str_replace('/', '\\/', $ref)]) as $needle) {
                $pattern = '/'.preg_quote($needle, '/').'(?![A-Za-z0-9._-])/';
                if (preg_match($pattern, $log) === 1) {
                    $found[] = $ref;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * `docker images --format '{{.Repository}}:{{.Tag}}\t{{.Size}}'`, one row per tag.
     *
     * @return list<array{tag: string, bytes: ?int}>
     */
    public static function parseImageList(string $output): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            $parts = explode("\t", trim($line), 2);
            if ($parts[0] === '') {
                continue;
            }
            try {
                $bytes = HostPrewarmPlan::parseBytes(trim($parts[1] ?? ''));
            } catch (\InvalidArgumentException $e) {
                $bytes = null;
            }
            $rows[] = ['tag' => $parts[0], 'bytes' => $bytes];
        }

        return $rows;
    }

    /**
     * `{{json .Metadata.LastTagTime}}` as a unix time. Null for Go's zero time,
     * which is what an image the daemon never recorded a tag time for reports.
     */
    public static function parseTagTime(string $value): ?int
    {
        $value = trim($value, " \t\n\r\"");
        if ($value === '' || $value === 'null' || str_starts_with($value, '0001-01-01')) {
            return null;
        }
        $time = strtotime($value);

        return $time === false || $time <= 0 ? null : $time;
    }
}
