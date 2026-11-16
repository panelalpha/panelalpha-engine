<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Dind\DindImageStore;
use GuzzleHttp\Client;

/**
 * A public repository's BuildKit cache in the cache registry, filled by the host ({@see BuildCacheBuilder}).
 * Accounts only read it; a source cloned with credentials gets none, since its layers hold private code.
 */
final class BuildCache
{
    public const PREFIX = 'buildcache';

    /** The tag of a repository deployed from its default branch. */
    public const DEFAULT_TAG = 'default';

    /** The build identity of a repository's Railpack plan, beside its Dockerfiles. */
    public const RAILPACK = 'railpack-plan';

    private const ACCEPT = 'application/vnd.oci.image.manifest.v1+json, application/vnd.oci.image.index.v1+json';

    private const MAX_TAG = 120;

    /** @var callable(string, string): bool url and Accept header to "the registry answered 200" */
    private $exists;

    public function __construct(?callable $exists = null)
    {
        $this->exists = $exists ?? self::httpExists(...);
    }

    /**
     * `buildcache/<host>/<path>:<tag>` for a public https clone URL, or null
     * when the source cannot have a shared cache.
     */
    public static function ref(string $repository, ?string $branch = null, string $dockerfile = 'Dockerfile'): ?string
    {
        $path = self::repositoryPath($repository);
        $tag = self::tag($branch, $dockerfile);

        return $path === null || $tag === null ? null : self::PREFIX.'/'.$path.':'.$tag;
    }

    /**
     * `github.com/owner/repo` from `https://github.com/Owner/Repo.git`; null for anything but plain https
     * and for a URL carrying a user, a token, a port or a query.
     */
    public static function repositoryPath(string $repository): ?string
    {
        $parts = parse_url(trim($repository));
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return null;
        }
        foreach (['user', 'pass', 'port', 'query', 'fragment'] as $key) {
            if (isset($parts[$key])) {
                return null;
            }
        }
        $path = preg_replace('/\.git$/i', '', trim((string) ($parts['path'] ?? ''), '/'));
        $full = strtolower((string) ($parts['host'] ?? '').'/'.$path);
        $component = '[a-z0-9]+(?:(?:\.|_|__|-+)[a-z0-9]+)*';

        return preg_match("#^{$component}(?:/{$component}){2,}$#", $full) === 1 ? $full : null;
    }

    /**
     * The branch as a registry tag, `default` without one, with a short hash wherever sanitising changed it
     * or a non-root Dockerfile is built, so two inputs never share a tag.
     */
    public static function tag(?string $branch, string $dockerfile = 'Dockerfile'): ?string
    {
        $branch = trim((string) $branch);
        $tag = $branch === '' ? self::DEFAULT_TAG : (string) preg_replace('/[^A-Za-z0-9_.-]/', '-', $branch);
        $tag = ltrim($tag, '.-');
        if ($branch !== '' && $tag !== $branch) {
            $tag = substr($tag, 0, self::MAX_TAG - 9).'-'.substr(sha1($branch), 0, 8);
        }
        $dockerfile = trim($dockerfile);
        if ($dockerfile !== '' && $dockerfile !== 'Dockerfile') {
            $tag = substr($tag, 0, self::MAX_TAG - 10).'-f'.substr(sha1($dockerfile), 0, 8);
        }

        return $tag === '' ? null : substr($tag, 0, self::MAX_TAG);
    }

    /** As an account's BuildKit imports it: the read-only registry, by its network name. */
    public static function accountRef(string $ref): string
    {
        return DindImageStore::CACHE_REGISTRY.'/'.$ref;
    }

    /** As the host publishes it: the writer on loopback. */
    public static function hostRef(string $ref): string
    {
        return DindImageStore::HOST_CACHE_REGISTRY.'/'.$ref;
    }

    /** The compose `build.cache_from` entry that imports it. */
    public static function cacheFrom(string $ref): string
    {
        return 'type=registry,ref='.self::accountRef($ref);
    }

    /** Whether $ref, a tag in the cache registry, is a build cache rather than an image. */
    public static function isCacheRef(string $ref): bool
    {
        return str_starts_with($ref, self::PREFIX.'/');
    }

    /** Whether the cache registry holds $ref, asked of the read-only instance accounts import from. */
    public function exists(string $ref): bool
    {
        [$repository, $tag] = explode(':', $ref, 2) + [1 => ''];
        if ($tag === '') {
            return false;
        }

        return ($this->exists)('http://'.DindImageStore::CACHE_REGISTRY."/v2/{$repository}/manifests/{$tag}", self::ACCEPT);
    }

    private static function httpExists(string $url, string $accept): bool
    {
        try {
            $response = (new Client(['connect_timeout' => 2, 'timeout' => 5, 'http_errors' => false]))
                ->head($url, ['headers' => ['Accept' => $accept]]);
        } catch (\Throwable) {
            return false;
        }

        return $response->getStatusCode() === 200;
    }
}
