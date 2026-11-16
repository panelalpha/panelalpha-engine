<?php

namespace App\Lib\Deploy\Compose;

/**
 * Which host paths a service's mounts, secrets and configs may bind: the checkout and the account's
 * ~/.panelalpha, never the Docker socket, checking every value an interpolated source can take.
 */
final class MountSourcePolicy
{
    /** @var list<string> */
    private const DOCKER_SOCKETS = ['/var/run/docker.sock', '/run/docker.sock'];

    /**
     * The socket itself, and the directories it sits in: `- /var/run:/var/run`
     * mounts the socket without naming it.
     */
    private const DOCKER_SOCKET_PATTERN = '#(^|:)\s*/(?:var/)?run(?:/docker\.sock)?(?:/)?(?::|$)#';

    /** The account's own writable tree, trusted like `leavesProject()` documents. */
    private const PANELALPHA_DIR = '.panelalpha';

    /** The checkout directory under the home, which `--project-directory` points at: relative binds resolve against it. */
    private const CHECKOUT_DIR = 'project';

    /** `~` and $HOME are root's home, or the account's, which holds ~/docker. */
    private const HOME_REFERENCE = '#^(~|\$HOME\b|\$\{HOME\})#';

    /**
     * @param  array<string, list<?string>|string>  $env
     */
    public static function isForbiddenMount(mixed $volume, array $env = [], ?string $accountUser = null, ?string $projectDir = null): bool
    {
        if (is_string($volume)) {
            return self::isForbiddenShortMount($volume, $env, $accountUser, $projectDir);
        }

        return is_array($volume) && self::isForbiddenLongMount($volume, $env, $accountUser, $projectDir);
    }

    /**
     * {@see isForbiddenSource()} for a source Compose will interpolate: every value it can take is
     * checked and an unknowable one refused, so `${DATA_DIR:-./data}` passes and `${X:-/var/run}` not.
     *
     * @param  array<string, list<?string>|string>  $env
     */
    public static function isForbiddenSourceIn(string $source, array $env, ?string $accountUser = null, ?string $projectDir = null): bool
    {
        if (! str_contains($source, '$')) {
            return self::isForbiddenSource($source, $accountUser, $projectDir);
        }
        $candidates = ComposeInterpolation::candidates($source, $env);
        if ($candidates === null) {
            return true;
        }
        foreach ($candidates as $candidate) {
            if (preg_match(self::DOCKER_SOCKET_PATTERN, trim($candidate)) === 1 || self::isForbiddenSource($candidate, $accountUser, $projectDir)) {
                return true;
            }
        }

        return false;
    }

    /** A source compose reads as a path, spelled so it is checked as one: `data` is `./data`. */
    public static function asPath(string $source): string
    {
        $source = trim($source);

        return $source === '' || preg_match('#^[./~$]#', $source) === 1 ? $source : './'.$source;
    }

    /**
     * Compose interpolates the whole entry before splitting it ({@see ShortVolume}), so each value is
     * split the same way: `- /var/lib/mysql` is an anonymous volume, `- /{/../etc:/x}` binds /etc.
     *
     * @param  array<string, list<?string>|string>  $env
     */
    private static function isForbiddenShortMount(string $volume, array $env, ?string $accountUser, ?string $projectDir): bool
    {
        if (preg_match(self::DOCKER_SOCKET_PATTERN, $volume) === 1) {
            return true;
        }
        $entries = ComposeInterpolation::candidates(trim($volume), $env);
        if ($entries === null) {
            return true;
        }
        foreach ($entries as $entry) {
            if (self::isForbiddenShortEntry($entry, $accountUser, $projectDir)) {
                return true;
            }
        }

        return false;
    }

    /** Only the source side is a host path; a named volume has no leading slash and is not one of these. */
    private static function isForbiddenShortEntry(string $entry, ?string $accountUser, ?string $projectDir): bool
    {
        if (preg_match(self::DOCKER_SOCKET_PATTERN, $entry) === 1) {
            return true;
        }
        $source = ShortVolume::source($entry);

        return $source !== null
            && (ShortVolume::isWindowsPath($source) || self::isForbiddenSource($source, $accountUser, $projectDir));
    }

    /**
     * A bind source is a path even without a leading `./`, and the socket's
     * directory counts here too: `source: /var/run` hands over the daemon.
     *
     * @param  array<mixed>  $volume
     * @param  array<string, list<?string>|string>  $env
     */
    private static function isForbiddenLongMount(array $volume, array $env, ?string $accountUser, ?string $projectDir): bool
    {
        $source = (string) ($volume['source'] ?? '');
        $target = (string) ($volume['target'] ?? '');
        if (($volume['type'] ?? null) === 'bind') {
            $source = self::asPath($source);
        }

        return in_array($source, self::DOCKER_SOCKETS, true)
            || in_array($target, self::DOCKER_SOCKETS, true)
            || preg_match(self::DOCKER_SOCKET_PATTERN, trim($source)) === 1
            || self::isForbiddenSourceIn($source, $env, $accountUser, $projectDir);
    }

    /**
     * Absolute sources only in the checkout or ~/.panelalpha: the rest is the account container's (/home:
     * inner daemon data-root, /run: init state, /usr: binaries init runs as root); a denylist would miss /tmp.
     */
    private static function isForbiddenSource(string $source, ?string $accountUser = null, ?string $projectDir = null): bool
    {
        $source = trim($source);
        if (self::escapesThroughSymlink($source, $accountUser, $projectDir)) {
            return true;
        }
        if (preg_match(self::HOME_REFERENCE, $source) === 1) {
            return true;
        }
        if (str_starts_with($source, '.')) {
            return self::leavesProject($source);
        }
        if (! str_starts_with($source, '/')) {
            return false;
        }

        return self::isForbiddenAbsoluteSource(self::normalisedAbsolute($source), $accountUser, $projectDir);
    }

    /**
     * Docker follows symlinks in a bind source, and a checkout keeps them. Safely skipped in the account-owned
     * ~/.panelalpha, whose 0700 would make {@see LinkedSource::escapes()} refuse an allowed bind.
     */
    private static function escapesThroughSymlink(string $source, ?string $accountUser, ?string $projectDir): bool
    {
        return $projectDir !== null && str_starts_with($projectDir, '/') && preg_match('#^[./]#', $source) === 1
            && ! self::isInOwnPanelalpha($source, $accountUser)
            && LinkedSource::escapes($source, $projectDir);
    }

    /** Whether $source, relative or absolute, resolves into the account's ~/.panelalpha. */
    private static function isInOwnPanelalpha(string $source, ?string $accountUser): bool
    {
        return str_starts_with($source, '/')
            ? self::isOwnPanelalpha(self::normalisedAbsolute($source), $accountUser)
            : self::relativeFirstSegment($source) === self::PANELALPHA_DIR;
    }

    /**
     * $source is normalised (`/home/u/.panelalpha/../docker` is /home/u/docker). The root is refused,
     * ~/.panelalpha, the one writable tree a rebuild keeps, allowed, anything else only in the checkout.
     */
    private static function isForbiddenAbsoluteSource(string $source, ?string $accountUser, ?string $projectDir): bool
    {
        if ($source === '/') {
            return true;
        }
        if (self::isOwnPanelalpha($source, $accountUser)) {
            return false;
        }
        if ($projectDir === null || ! str_starts_with($projectDir, '/')) {
            return true;
        }
        $checkout = self::checkoutInAccount($projectDir);

        return $source !== $checkout && ! str_starts_with($source, $checkout.'/');
    }

    /** The checkout as the account container sees it, as LinkedSource maps it. */
    private static function checkoutInAccount(string $projectDir): string
    {
        $projectDir = rtrim($projectDir, '/');

        return '/home/'.basename(dirname($projectDir)).'/'.basename($projectDir);
    }

    /** Whether normalised absolute $source is the account's ~/.panelalpha or under it. */
    private static function isOwnPanelalpha(string $source, ?string $accountUser): bool
    {
        if ($accountUser === null || preg_match('/^[a-z_][a-z0-9_.-]*$/i', $accountUser) !== 1) {
            return false;
        }
        $keep = "/home/{$accountUser}/.panelalpha";

        return $source === $keep || str_starts_with($source, $keep.'/');
    }

    private static function normalisedAbsolute(string $source): string
    {
        $segments = [];
        foreach (explode('/', $source) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    /**
     * A relative bind resolves against ~/project. Only the checkout and ~/.panelalpha and paths under them are allowed,
     * so no bind reaches the home itself, its docker/ data-root or anything past ~.
     */
    private static function leavesProject(string $source): bool
    {
        $first = self::relativeFirstSegment($source);

        return $first !== self::CHECKOUT_DIR && $first !== self::PANELALPHA_DIR;
    }

    /**
     * The first path component once `.`/`..` are resolved against `~/project`: a name, null for the home
     * itself (`../`), or false for more `..` than there were parts to pop (past `~`).
     */
    private static function relativeFirstSegment(string $source): string|false|null
    {
        $segments = [self::CHECKOUT_DIR];
        foreach (explode('/', $source) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return false;
                }
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return $segments[0] ?? null;
    }
}
