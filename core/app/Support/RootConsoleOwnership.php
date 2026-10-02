<?php

namespace App\Support;

use Symfony\Component\Process\Process;

/**
 * `pae` runs artisan as root, everything else as www-data. Whatever root
 * creates first in storage/ or bootstrap/cache (a cache key, a scheduler lock
 * directory, a log) www-data can never write again, so hand it back.
 */
final class RootConsoleOwnership
{
    public const OWNER = 'www-data';

    public static function applies(bool $console, int $euid, bool $unitTests): bool
    {
        return $console && $euid === 0 && !$unitTests;
    }

    /**
     * Repair once now, for leftovers of a run that was killed, and again when
     * this run exits.
     */
    public static function register(): void
    {
        self::repair();
        register_shutdown_function([self::class, 'repair']);
    }

    public static function repair(): void
    {
        $argv = self::repairArgv([storage_path(), base_path('bootstrap/cache')], self::owner());
        if ($argv === null) {
            return;
        }
        try {
            (new Process($argv))->setTimeout(60)->run();
        } catch (\Throwable $e) {
            // Never fail the command over this; the next root run or a restart repairs it.
        }
    }

    /**
     * -H descends into a path given as a symlink; -h changes a link itself,
     * never what it points at.
     *
     * @param list<string> $paths
     * @param array{uid: int, gid: int}|null $owner
     * @return list<string>|null
     */
    public static function repairArgv(array $paths, ?array $owner): ?array
    {
        $paths = array_values(array_filter($paths, 'is_dir'));
        if ($owner === null || $paths === []) {
            return null;
        }

        return ['find', '-H', ...$paths, '-user', '0', '-exec', 'chown', '-h', "{$owner['uid']}:{$owner['gid']}", '{}', '+'];
    }

    /** @return array{uid: int, gid: int}|null */
    private static function owner(): ?array
    {
        $entry = function_exists('posix_getpwnam') ? posix_getpwnam(self::OWNER) : false;

        return is_array($entry) ? ['uid' => (int) $entry['uid'], 'gid' => (int) $entry['gid']] : null;
    }
}
