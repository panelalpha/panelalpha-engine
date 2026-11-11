<?php

namespace App\Lib\Deploy\CacheManager;

use Illuminate\Container\Container;

/**
 * Where scripts/publish-base-images.sh pushed one of our base images, so a host pulls it instead of compiling it.
 * A pulled copy counts only when its label holds the hash of the Dockerfile this engine would build.
 */
final class PublishedBaseImage
{
    /** The label the publish script writes; the script spells the same name. */
    public const DOCKERFILE_LABEL = 'panelalpha.dockerfile.sha256';

    public const DEFAULT_REGISTRY = 'ghcr.io/panelalpha';

    /** `ghcr.io/panelalpha/php:<tag>` for `panelalpha/php:<tag>`, or null when it is not published or publishing is off. */
    public static function ref(string $tag): ?string
    {
        $registry = self::registry();
        if ($registry === null || ! self::isPublished($tag)) {
            return null;
        }
        [$repository, $version] = explode(':', $tag, 2);
        $ref = $registry.'/'.basename($repository).':'.$version;

        return ImageTransfer::isSafeImageRef($ref) ? $ref : null;
    }

    public static function dockerfileSha(string $dockerfile): string
    {
        return hash('sha256', $dockerfile);
    }

    private static function isPublished(string $tag): bool
    {
        foreach (ImageCatalog::prewarmed() as $item) {
            if ($item['kind'] === HostPrewarmPlan::KIND_BUILD && $item['ref'] === $tag) {
                return true;
            }
        }

        return false;
    }

    /** `deploy.base_image_registry`; empty or `none` turns pulling off, and plain unit tests have no config. */
    private static function registry(): ?string
    {
        if (! Container::getInstance()->bound('config')) {
            return null;
        }
        $value = config('deploy.base_image_registry', self::DEFAULT_REGISTRY);
        $registry = is_string($value) ? rtrim(trim($value), '/') : '';

        return $registry === '' || strtolower($registry) === 'none' ? null : $registry;
    }
}
