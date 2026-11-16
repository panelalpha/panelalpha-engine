<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;
use App\Lib\Deploy\Template\Template;

/**
 * A PanelAlpha-owned derivative of a runtime's official slim image, with the apt packages a source
 * build needs baked in once on the host.
 */
abstract class RuntimeBaseImage
{
    /** The runtime's name in the catalogue and in the stub's `<runtime>_image` variable. */
    protected const RUNTIME = '';

    /** Beyond this, another ~250 MB image is not worth it and the project installs its own. */
    public const MAX_PACKAGES = 0;

    /** Where the engine puts this image, or null when the catalogue describes no build for this runtime. */
    public static function repository(): ?string
    {
        return RuntimeImageCatalog::repository(static::RUNTIME);
    }

    public static function stubName(): ?string
    {
        return RuntimeImageCatalog::stub(static::RUNTIME);
    }

    protected static function upstreamRepository(): ?string
    {
        return RuntimeImageCatalog::upstreamRepository(static::RUNTIME);
    }

    /**
     * Null for anything we cannot prebuild, or that {@see BuiltImage} could not recognise back:
     * no catalogue entry, not a plain official tag, or no package set within {@see MAX_PACKAGES}.
     *
     * @param  list<string>  $packages
     */
    public static function tag(string $image, array $packages): ?string
    {
        $repository = static::repository();
        $upstream = static::upstreamRepository();
        if ($repository === null || $upstream === null || static::stubName() === null) {
            return null;
        }
        if (preg_match('#^'.preg_quote($upstream, '#').':([a-z0-9._-]+)$#i', trim($image), $matches) !== 1) {
            return null;
        }
        $packages = static::normalizePackages($packages);
        if ($packages === [] || count($packages) > static::MAX_PACKAGES) {
            return null;
        }

        return $repository.':'.$matches[1].'-pa'.static::fingerprint($packages);
    }

    /**
     * Sorted and de-duplicated apt names (letters, digits and `.+-` only, nothing else near a shell).
     *
     * @param  list<string>  $packages
     * @return list<string>
     */
    public static function normalizePackages(array $packages): array
    {
        $clean = [];
        foreach ($packages as $name) {
            if (is_string($name) && preg_match('/^[a-z0-9][a-z0-9.+-]*$/i', $name) === 1) {
                $clean[strtolower($name)] = true;
            }
        }
        $names = array_keys($clean);
        sort($names);

        return $names;
    }

    /**
     * @param  list<string>  $packages
     */
    public static function fingerprint(array $packages): string
    {
        return substr(sha1(implode(' ', static::normalizePackages($packages))), 0, 8);
    }

    /** The official tag this was built from, so a host that pruned it can rebuild; null if not ours. */
    public static function sourceImage(string $tag): ?string
    {
        $source = BuiltImage::sourceImage($tag);

        return $source !== null && BuiltImage::runtimeFor($tag) === static::RUNTIME ? $source : null;
    }

    /**
     * @param  list<string>  $packages
     */
    public static function dockerfile(string $image, array $packages): ?string
    {
        $stub = static::stubName();
        if ($stub === null) {
            return null;
        }

        return Template::named($stub)->render([
            static::RUNTIME.'_image' => $image,
            'packages' => implode(' ', static::normalizePackages($packages)),
        ]);
    }
}
