<?php

namespace App\Lib\Deploy\Platform\Dockerfile;

/**
 * Puts git in the builder image.
 *
 * MDX and content pipelines (content-collections, next-sitemap, …) shell out
 * to `git log` during the build, and the slim base images ship without it.
 */
final class GitInstall
{
    private const APK = 'apk add --no-cache git';

    private const APT = 'apt-get update && apt-get install -y --no-install-recommends git'
        .' && rm -rf /var/lib/apt/lists/*';

    public static function command(string $baseImage): string
    {
        return self::isAlpine($baseImage) ? self::APK : self::APT;
    }

    private static function isAlpine(string $baseImage): bool
    {
        return str_contains(strtolower($baseImage), 'alpine');
    }
}
