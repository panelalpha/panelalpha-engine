<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Dind\DindImageStore;

/**
 * Which host images the daily prune may remove.
 *
 * Only images the deploy path puts on the host are in scope: the bases the
 * engine builds (`panelalpha/php:*-x…` variants, Ruby/Python bases, superseded
 * recipe dates) and anything from a repository `config/core/images.yaml`
 * resolves runtimes from (golang, node, python, dotnet, …). The engine's own
 * images and a repo's compose sidecars are never touched here; the sidecars are
 * account teardown's ({@see \App\Lib\Deploy\Dind\DindAccountCleanup}).
 *
 * Within scope an image is kept when any of these hold:
 * - the prewarm catalogue names it (evicting one sends the next deploy back to
 *   a compile or to Docker Hub, the reason `docker image prune -a` is wrong);
 * - a host container uses it;
 * - it was pulled, built or tagged on this host inside the window
 *   (`LastTagTime`), which also covers a deploy that just fetched it;
 * - an account's latest deploy log, or any deploy log written inside the
 *   window, names it ({@see mentionedIn()}; the caller reads the logs);
 * - its age cannot be read. "Cannot judge" means keep.
 */
final class HostImageRetention
{
    public const DEFAULT_MAX_AGE = '3d';

    public const DEFAULT_BUILD_CACHE_MAX_AGE = '24h';

    /** The name an image goes by, without the loopback registry alias the load path tags. */
    public static function logicalRef(string $tag): string
    {
        $alias = DindImageStore::HOST_CACHE_REGISTRY . '/';

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
     * @param list<string> $catalog every ref images.yaml names ({@see ImageCatalog::all()})
     */
    public static function inScope(string $ref, array $catalog): bool
    {
        if (BuiltImage::isOurs($ref)) {
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

    /**
     * Images old enough and unprotected enough to remove, before the deploy
     * logs have had their say.
     *
     * @param list<array{tag: string, bytes: ?int, since: ?int}> $images one row
     *        per host tag; since = LastTagTime as a unix time, null when unknown
     * @param list<string> $prewarmed the prewarm catalogue's refs
     * @param list<string> $catalog every ref images.yaml names
     * @param list<string> $containerRefs images host containers were started from
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

        $groups = [];
        foreach ($images as $image) {
            $tag = $image['tag'];
            if ($tag === '' || str_contains($tag, '<none>') || !ImageTransfer::isSafeImageRef($tag)) {
                continue;
            }
            $ref = self::logicalRef($tag);
            $group = $groups[$ref] ?? ['tags' => [], 'bytes' => null, 'since' => null, 'known' => true];
            $group['tags'][] = $tag;
            $group['bytes'] = $image['bytes'] ?? $group['bytes'];
            // The newest tag decides, and one unknown age makes the whole group unknown.
            if ($image['since'] === null) {
                $group['known'] = false;
            } else {
                $group['since'] = max($group['since'] ?? 0, $image['since']);
            }
            $groups[$ref] = $group;
        }

        $candidates = [];
        foreach ($groups as $ref => $group) {
            if (isset($protected[$ref]) || !self::inScope($ref, $catalog)) {
                continue;
            }
            if (!$group['known'] || $group['since'] === null || $group['since'] > $now - $maxAge) {
                continue;
            }
            $candidates[$ref] = ['tags' => $group['tags'], 'bytes' => $group['bytes'], 'since' => $group['since']];
        }

        return $candidates;
    }

    /**
     * Which of $refs a deploy log names. Log lines are JSON with escaped
     * slashes, so both spellings count, and a ref must end where the name
     * ends: `…-pa20260922` is not named by a line about `…-pa20260922-x1234`.
     *
     * @param list<string> $refs
     * @return list<string>
     */
    public static function mentionedIn(string $log, array $refs): array
    {
        $found = [];
        foreach ($refs as $ref) {
            foreach (array_unique([$ref, str_replace('/', '\\/', $ref)]) as $needle) {
                $pattern = '/' . preg_quote($needle, '/') . '(?![A-Za-z0-9._-])/';
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
