<?php

namespace App\Lib\Deploy\Compose;

/**
 * Where a bind source in the account's home really leads, since dockerd follows a committed symlink
 * (`data -> /var/run`). Core sees the home at the account's path; a target outside it is never followed.
 */
final class LinkedSource
{
    /** The kernel's own limit on links followed in one lookup. */
    private const MAX_LINKS = 40;

    private const PANELALPHA_DIR = '.panelalpha';

    /**
     * Whether $source (relative to $projectDir, or absolute as the account sees it) leaves the home, goes
     * through a link ending outside ~/project and ~/.panelalpha, or cannot be inspected. No link: the text checks own it.
     */
    public static function escapes(string $source, string $projectDir): bool
    {
        $projectDir = rtrim($projectDir, '/');
        $home = dirname($projectDir);
        $project = basename($projectDir);
        $accountHome = '/home/'.basename($home);
        if (! is_dir($home)) {
            return false;
        }

        $start = self::walkStart(trim($source), $project, $accountHome);
        if (is_bool($start)) {
            return $start;
        }

        clearstatcache();
        $walk = self::walk(['segments' => $start[0], 'pending' => $start[1], 'missing' => 0, 'followed' => 0], $home, $accountHome);
        if ($walk === null) {
            return true;
        }

        if ($walk['followed'] === 0) {
            return false;
        }

        return ! in_array($walk['segments'][0] ?? null, [$project, self::PANELALPHA_DIR], true);
    }

    /**
     * Outside the home only an absolute source's text can be judged, but a `..` in it may climb back in.
     *
     * @return bool|array{0: list<string>, 1: list<string>} the verdict, or the walk's segments and pending parts
     */
    private static function walkStart(string $source, string $project, string $accountHome): bool|array
    {
        if (str_starts_with($source, '/')) {
            $pending = self::inHome($source, $accountHome);
            if ($pending === null) {
                return in_array('..', explode('/', $source), true);
            }

            return [[], $pending];
        }

        return [[$project], explode('/', $source)];
    }

    /**
     * `missing` counts the trailing segments that do not exist (yet); null once the walk escapes.
     *
     * @param  array{segments: list<string>, pending: list<string>, missing: int, followed: int}  $walk
     * @return array{segments: list<string>, pending: list<string>, missing: int, followed: int}|null
     */
    private static function walk(array $walk, string $home, string $accountHome): ?array
    {
        while ($walk['pending'] !== []) {
            $name = array_shift($walk['pending']);
            if ($name === '' || $name === '.') {
                continue;
            }
            $walk = $name === '..' ? self::stepBack($walk) : self::stepInto($walk, $name, $home, $accountHome);
            if ($walk === null) {
                return null;
            }
        }

        return $walk;
    }

    /**
     * Back out of what compose would create, and onto the real tree.
     *
     * @param  array{segments: list<string>, pending: list<string>, missing: int, followed: int}  $walk
     * @return array{segments: list<string>, pending: list<string>, missing: int, followed: int}|null
     */
    private static function stepBack(array $walk): ?array
    {
        if ($walk['segments'] === []) {
            return null;
        }
        array_pop($walk['segments']);
        $walk['missing'] = max(0, $walk['missing'] - 1);

        return $walk;
    }

    /**
     * An absent part is fine, as compose creates it; a directory we may not search could hide a link.
     *
     * @param  array{segments: list<string>, pending: list<string>, missing: int, followed: int}  $walk
     * @return array{segments: list<string>, pending: list<string>, missing: int, followed: int}|null
     */
    private static function stepInto(array $walk, string $name, string $home, string $accountHome): ?array
    {
        $parent = self::join($home, $walk['segments']);
        $walk['segments'][] = $name;
        if ($walk['missing'] > 0) {
            $walk['missing']++;

            return $walk;
        }
        $path = $parent.'/'.$name;
        if (! is_link($path)) {
            if (@lstat($path) === false) {
                if (! is_executable($parent)) {
                    return null;
                }
                $walk['missing'] = 1;
            }

            return $walk;
        }

        array_pop($walk['segments']);

        return self::throughLink($walk, $path, $accountHome);
    }

    /**
     * @param  array{segments: list<string>, pending: list<string>, missing: int, followed: int}  $walk
     * @return array{segments: list<string>, pending: list<string>, missing: int, followed: int}|null
     */
    private static function throughLink(array $walk, string $path, string $accountHome): ?array
    {
        $target = @readlink($path);
        if ($target === false || ++$walk['followed'] > self::MAX_LINKS) {
            return null;
        }
        if (str_starts_with($target, '/')) {
            $next = self::inHome($target, $accountHome);
            if ($next === null) {
                return null;
            }
            $walk['segments'] = [];
        } else {
            $next = explode('/', $target);
        }
        $walk['pending'] = [...$next, ...$walk['pending']];

        return $walk;
    }

    /**
     * The parts of absolute $path below $home, `..` kept for the walk, or null when it does not start there.
     * Not normalised first: `link/..` is the parent of the link's target.
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
        return $segments === [] ? $home : $home.'/'.implode('/', $segments);
    }
}
