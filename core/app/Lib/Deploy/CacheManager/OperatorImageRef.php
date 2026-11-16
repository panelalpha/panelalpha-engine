<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Dind\DindImageStore;
use App\Models\OperatorImage;

/**
 * Which names an operator image may take. Projects pull from the cache registry before Docker Hub, so a name
 * that could stand in for someone else's image, or one of ours, is refused.
 */
final class OperatorImageRef
{
    public const INVALID = 'operator_image_ref_invalid';

    public const RESERVED = 'operator_image_ref_reserved';

    public const REGISTRY_HOST = 'operator_image_ref_registry_host';

    private const DOCKER_HUB_HOSTS = ['docker.io', 'index.docker.io', 'registry-1.docker.io'];

    private const RESERVED_PREFIXES = ['panelalpha/', 'ghcr.io/panelalpha/'];

    /**
     * Why $ref cannot be an operator image of $source, or null when it can.
     *
     * @param  list<string>|null  $catalogue  every ref images.yaml names; null reads it
     * @return array{code: string, message: string}|null
     */
    public static function problem(string $ref, string $source, ?array $catalogue = null): ?array
    {
        if (! self::isTagged($ref)) {
            return self::fail(self::INVALID, "'{$ref}' is not an image name with a tag, such as whmcs-dev-base:php8.3.");
        }
        $normalized = self::normalize($ref);
        if (self::isOwnRegistry($ref) || self::isReservedName($normalized)) {
            return self::fail(self::RESERVED, "'{$ref}' is a name the engine uses for its own images.");
        }
        if (self::namesCatalogueRepository($normalized, $catalogue ?? ImageCatalog::all())) {
            return self::fail(self::RESERVED, "The engine's image catalogue already uses the name ".HostImageRetention::repository($normalized)
                .': pick another name, or have the engine prewarm the catalogue image instead.');
        }
        if ($source !== OperatorImage::SOURCE_PULL && self::hasRegistryHost($ref)) {
            return self::fail(self::REGISTRY_HOST, "A {$source} image is named without a registry host, so it cannot stand in for an image on that registry: drop the host from '{$ref}', or add it with source pull.");
        }

        return null;
    }

    /** Docker Hub's spellings of the same name collapse to the short one: `docker.io/library/debian:12` is `debian:12`. */
    public static function normalize(string $ref): string
    {
        $parts = explode('/', trim($ref));
        if (count($parts) > 1 && in_array(strtolower($parts[0]), self::DOCKER_HUB_HOSTS, true)) {
            array_shift($parts);
        }
        if (count($parts) === 2 && $parts[0] === 'library') {
            array_shift($parts);
        }

        return implode('/', $parts);
    }

    public static function hasRegistryHost(string $ref): bool
    {
        $parts = explode('/', $ref);
        $first = $parts[0];

        return count($parts) > 1
            && (str_contains($first, '.') || str_contains($first, ':') || $first === 'localhost');
    }

    private static function isTagged(string $ref): bool
    {
        if (! ImageTransfer::isSafeImageRef($ref) || str_contains($ref, '@') || $ref !== strtolower($ref)) {
            return false;
        }
        $slash = strrpos($ref, '/');
        $colon = strrpos($ref, ':');

        return $colon !== false && ($slash === false || $colon > $slash);
    }

    private static function isOwnRegistry(string $ref): bool
    {
        foreach ([DindImageStore::CACHE_REGISTRY, DindImageStore::HOST_CACHE_REGISTRY, DindImageStore::PROXY_REGISTRY] as $registry) {
            if (str_starts_with($ref, $registry.'/')) {
                return true;
            }
        }

        return false;
    }

    private static function isReservedName(string $normalized): bool
    {
        foreach (self::RESERVED_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return BuiltImage::isOurs($normalized) || RailpackCache::isRailpackImage($normalized);
    }

    /**
     * @param  list<string>  $catalogue
     */
    private static function namesCatalogueRepository(string $normalized, array $catalogue): bool
    {
        $repository = HostImageRetention::repository($normalized);
        foreach ($catalogue as $named) {
            if (HostImageRetention::repository(self::normalize($named)) === $repository) {
                return true;
            }
        }

        return false;
    }

    /** @return array{code: string, message: string} */
    private static function fail(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}
