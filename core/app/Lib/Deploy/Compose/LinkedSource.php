<?php

namespace App\Lib\Deploy\Compose;

/**
 * Where a bind source in the account's home really leads. The account's dockerd
 * follows symlinks in a bind source, and a git checkout keeps them, so a
 * repository committing `data -> /var/run` turns `./data:/sock` into the
 * account's docker socket while the text of the source looks harmless.
 *
 * Core sees the account's home at the same path as the account container does
 * (both bind /home/<user>), so links inside it are read here. A link target
 * outside that home is never followed: core's filesystem there is not the
 * account's, and leaving the home is refused anyway.
 */
final class LinkedSource
{
    /** The kernel's own limit on links followed in one lookup. */
    private const MAX_LINKS = 40;

    private const PANELALPHA_DIR = '.panelalpha';

    /**
     * Whether $source leaves the account's home, goes through a symlink that
     * ends outside ~/project and ~/.panelalpha, or cannot be inspected.
     * $source is relative to $projectDir (core's path of ~/project, where the
     * run file sits), or an absolute path as the account container sees it.
     * A part that does not exist yet is taken as written: compose creates it.
     */
    public static function escapes(string $source, string $projectDir): bool
    {
        $projectDir = rtrim($projectDir, '/');
        $home = dirname($projectDir);
        $project = basename($projectDir);
        $accountHome = '/home/' . basename($home);
        if (!is_dir($home)) {
            // No checkout to look into, so no link to follow.
            return false;
        }

        $source = trim($source);
        if (str_starts_with($source, '/')) {
            $pending = self::inHome($source, $accountHome);
            if ($pending === null) {
                // Outside the home only its text can be judged, but a `..` in it
                // may climb back in through a part of the account's own tree.
                return in_array('..', explode('/', $source), true);
            }
            $segments = [];
        } else {
            $segments = [$project];
            $pending = explode('/', $source);
        }

        clearstatcache();
        $followed = 0;
        // How many trailing parts of $segments do not exist (yet).
        $missing = 0;
        while ($pending !== []) {
            $name = array_shift($pending);
            if ($name === '' || $name === '.') {
                continue;
            }
            if ($name === '..') {
                if ($segments === []) {
                    return true;
                }
                array_pop($segments);
                // Back out of what compose would create, and onto the real tree.
                $missing = max(0, $missing - 1);
                continue;
            }
            $parent = self::join($home, $segments);
            $segments[] = $name;
            if ($missing > 0) {
                $missing++;
                continue;
            }
            $path = $parent . '/' . $name;
            if (!is_link($path)) {
                if (@lstat($path) === false) {
                    // Absent is fine; a directory we may not search could hide a link.
                    if (!is_executable($parent)) {
                        return true;
                    }
                    $missing = 1;
                }
                continue;
            }

            array_pop($segments);
            $target = @readlink($path);
            if ($target === false || ++$followed > self::MAX_LINKS) {
                return true;
            }
            if (str_starts_with($target, '/')) {
                $next = self::inHome($target, $accountHome);
                if ($next === null) {
                    return true;
                }
                $segments = [];
            } else {
                $next = explode('/', $target);
            }
            $pending = [...$next, ...$pending];
        }

        // No link on the way: the text is the path, and the text checks own it.
        if ($followed === 0) {
            return false;
        }

        return !in_array($segments[0] ?? null, [$project, self::PANELALPHA_DIR], true);
    }

    /**
     * The parts of absolute $path below $home, `..` kept for the walk, or null
     * when it does not start there. Not normalised first: `link/..` is the
     * parent of the link's target, not the directory holding the link.
     *
     * @return list<string>|null
     */
    private static function inHome(string $path, string $home): ?array
    {
        $parts = array_values(array_filter(
            explode('/', $path),
            static fn (string $part): bool => $part !== '' && $part !== '.'
        ));
        $prefix = array_values(array_filter(explode('/', $home), static fn (string $part): bool => $part !== ''));

        return array_slice($parts, 0, count($prefix)) === $prefix ? array_slice($parts, count($prefix)) : null;
    }

    /** @param list<string> $segments */
    private static function join(string $home, array $segments): string
    {
        return $segments === [] ? $home : $home . '/' . implode('/', $segments);
    }
}
