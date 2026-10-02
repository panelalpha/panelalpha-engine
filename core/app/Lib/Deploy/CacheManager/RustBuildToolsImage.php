<?php

namespace App\Lib\Deploy\CacheManager;

/**
 * The Rust build image plus the apt packages a project's build scripts need
 * ({@see \App\Lib\Deploy\Platform\Runtime\RustBuildTools}): protoc, cmake,
 * libclang, mold. Built on the host once per package set and used only by the
 * host compile; the app still runs in the slim runtime image.
 *
 * The tag fingerprints the Dockerfile, so two package sets never share an image.
 */
final class RustBuildToolsImage
{
    public const REPOSITORY = 'panelalpha/build-rust';

    /** @param list<string> $packages */
    public static function tag(string $image, array $packages): ?string
    {
        $dockerfile = self::dockerfile($image, $packages);
        if ($dockerfile === null) {
            return null;
        }
        $slug = trim((string) preg_replace('/[^a-z0-9._-]+/', '-', strtolower(trim($image))), '-.');

        return self::REPOSITORY . ':' . substr($slug, 0, 80) . '-pa' . substr(sha1($dockerfile), 0, 8);
    }

    /**
     * Null for an unsafe image reference, no packages, or a package name apt
     * would not accept.
     *
     * @param list<string> $packages
     */
    public static function dockerfile(string $image, array $packages): ?string
    {
        $image = trim($image);
        if (!ImageTransfer::isSafeImageRef($image) || $packages === []) {
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
            'RUN apt-get update && apt-get install -y --no-install-recommends ' . implode(' ', $packages)
                . ' && rm -rf /var/lib/apt/lists/*',
            '',
        ]);
    }
}
