<?php

namespace App\Lib\Deploy\Dind;

use App\Lib\Deploy\Template\Template;

/**
 * Storage heuristics and reclaim scripts for heavy DinD builds (thresholds, ENOSPC vocabulary), reached
 * through DindAccountStorage.
 */
class DindBuildStorage
{
    /** 4 GiB */
    public const MIN_FREE_BYTES = 4294967296;

    /** 1 GiB */
    public const LARGE_BUILD_CACHE_BYTES = 1073741824;

    public static function isDiskFullError(string $output): bool
    {
        $needles = [
            'ERR_PNPM_ENOSPC',
            'ENOSPC: no space left on device',
            'database or disk is full',
            'ERR_SQLITE_ERROR',
            'no space left on device',
        ];

        foreach ($needles as $needle) {
            if (stripos($output, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    public static function parseDfAvailableBytes(string $dfOutput): ?int
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($dfOutput)) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'Filesystem') === 0) {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if (count($parts) < 6) {
                continue;
            }
            $availableIndex = count($parts) >= 7 ? 4 : 3;
            $availableKb = $parts[$availableIndex] ?? null;
            if (! is_string($availableKb) || ! ctype_digit($availableKb)) {
                continue;
            }

            return (int) $availableKb * 1024;
        }

        return null;
    }

    /**
     * Remove the entire inner Docker data-root (user delete / failed deploy).
     */
    public static function fullWipeScript(): string
    {
        return self::script('wipe-docker-root');
    }

    private static function script(string $name): string
    {
        return rtrim(Template::named('script/'.$name)->render([]), "\n");
    }

    public static function shouldReclaimBeforeBuild(
        ?int $availableBytes,
        ?int $buildCacheBytes,
        bool $hasInnerContainers
    ): bool {
        if ($hasInnerContainers) {
            return false;
        }

        if ($availableBytes !== null && $availableBytes < self::MIN_FREE_BYTES) {
            return true;
        }

        if (
            $availableBytes !== null
            && $buildCacheBytes !== null
            && $availableBytes < (self::MIN_FREE_BYTES * 2)
            && $buildCacheBytes >= self::LARGE_BUILD_CACHE_BYTES
        ) {
            return true;
        }

        return false;
    }
}
