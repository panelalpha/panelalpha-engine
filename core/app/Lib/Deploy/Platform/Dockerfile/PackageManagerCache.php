<?php

namespace App\Lib\Deploy\Platform\Dockerfile;

/**
 * A BuildKit `RUN --mount` cache keeps a package manager's download cache out of the image layer and
 * persists it in the account's own daemon between builds.
 */
final class PackageManagerCache
{
    /**
     * Each package manager's download cache path, as root, in the official
     * images the recipes build on. A wrong path here silently caches nothing.
     *
     * @var array<string, string>
     */
    private const DIRECTORIES = [
        'npm' => '/root/.npm',
        'yarn' => '/usr/local/share/.cache/yarn',
        'pnpm' => '/root/.local/share/pnpm/store',
        'bun' => '/root/.bun/install/cache',
        'pip' => '/root/.cache/pip',
    ];

    /**
     * The `--mount=type=cache,…,sharing=locked ` part of the RUN line (and its cache key), or '' for an unknown
     * manager; `sharing=locked` because npm and yarn are not safe against concurrent writes to their cache.
     */
    public static function mountFor(string $packageManager): string
    {
        $directory = self::DIRECTORIES[$packageManager] ?? null;
        if ($directory === null) {
            return '';
        }

        return sprintf('--mount=type=cache,target=%s,sharing=locked ', $directory);
    }

    /** The cache directory for a package manager, for tests and diagnostics. */
    public static function directoryFor(string $packageManager): ?string
    {
        return self::DIRECTORIES[$packageManager] ?? null;
    }
}
