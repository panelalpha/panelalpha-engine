<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Platform\Runtime\RustBuildTools;

/**
 * The Rust build image plus the apt packages build scripts need ({@see RustBuildTools}), built once per
 * package set on the host for the host compile only; the tag fingerprints the Dockerfile.
 */
final class RustBuildToolsImage
{
    use FingerprintedTag;

    public const REPOSITORY = 'panelalpha/build-rust';

    /** @param list<string> $packages */
    public static function tag(string $image, array $packages): ?string
    {
        $dockerfile = self::dockerfile($image, $packages);
        if ($dockerfile === null) {
            return null;
        }

        return self::fingerprintedTag($image, $dockerfile);
    }

    /**
     * Null for an unsafe image reference, no packages, or a package name apt
     * would not accept.
     *
     * @param  list<string>  $packages
     */
    public static function dockerfile(string $image, array $packages): ?string
    {
        $image = trim($image);
        if (! ImageTransfer::isSafeImageRef($image) || $packages === []) {
            return null;
        }
        foreach ($packages as $package) {
            if (preg_match('/^[a-z0-9][a-z0-9.+-]*$/', $package) !== 1) {
                return null;
            }
        }
        $packages = array_values(array_unique($packages));
        sort($packages);

        return implode("\n", [
            "FROM {$image}",
            'RUN apt-get update && apt-get install -y --no-install-recommends '.implode(' ', $packages)
                .' && rm -rf /var/lib/apt/lists/*',
            '',
        ]);
    }
}
