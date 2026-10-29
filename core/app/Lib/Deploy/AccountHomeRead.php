<?php

namespace App\Lib\Deploy;

use App\System\EnginePaths;

/**
 * How detection and inspection read a project's files as www-data.
 *
 * A checkout is the tenant's: any file in it may be a symlink, and so may any
 * directory above it, at any moment. Followed, a link hands www-data whatever
 * it can open on the host, including another account's world-readable `.env`
 * or core's own. So the file is opened first and then located by the kernel,
 * through /proc/self/fd, and read only when it is in the account home its path
 * names, or, for a path in no home, in no home either and inside the checkout
 * being inspected that the path names. Asking where the opened file is, rather
 * than resolving the path and opening it after, leaves nothing to swap in
 * between. Nothing is read before that answer.
 */
final class AccountHomeRead
{
    private const OPEN_FILES = '/proc/self/fd';

    private static ?string $homesDir = null;

    /** @var list<array{dir: string, real: string}> */
    private static array $checkouts = [];

    /**
     * file_get_contents() of $path, at most $length bytes: false, silently, when
     * it is missing, unreadable or not a regular file, and when the file opened
     * is outside the account home or the inspected checkout that $path names.
     */
    public static function contents(string $path, ?int $length = null): string|false
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            return self::isRegularFile($handle) && self::staysInItsRoot($path, $handle)
                ? @stream_get_contents($handle, $length)
                : false;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Runs $read with every read of a path in $checkout held inside $checkout.
     * A checkout in an account home keeps the home's rule, so inspecting a
     * project reads what its deploy reads.
     *
     * @template T
     *
     * @param  \Closure(): T  $read
     * @return T
     */
    public static function confinedTo(string $checkout, \Closure $read): mixed
    {
        if (self::isInAccountHome($checkout)) {
            return $read();
        }
        self::$checkouts[] = self::checkout($checkout);
        try {
            return $read();
        } finally {
            array_pop(self::$checkouts);
        }
    }

    public static function isInAccountHome(string $path): bool
    {
        return self::accountOf(self::absolute($path)) !== null;
    }

    /** Tests: where the account homes are; null restores /home. */
    public static function useHomesDir(?string $dir): void
    {
        self::$homesDir = $dir;
    }

    /** @param resource $handle */
    private static function isRegularFile($handle): bool
    {
        $stat = fstat($handle);

        return $stat !== false && ($stat['mode'] & 0170000) === 0100000;
    }

    /** @param resource $handle */
    private static function staysInItsRoot(string $path, $handle): bool
    {
        $path = self::absolute($path);
        $opened = self::openedPath($handle);

        return $opened !== null
            && self::accountOf($opened) === self::accountOf($path)
            && self::staysInItsCheckout($path, $opened);
    }

    private static function staysInItsCheckout(string $path, string $opened): bool
    {
        $checkout = self::checkoutOf($path);

        return $checkout === null || self::isBelow($opened, $checkout['real']);
    }

    /**
     * The innermost checkout being inspected that $path is in, by the path it
     * was given as or the one it resolves to.
     *
     * @return array{dir: string, real: string}|null
     */
    private static function checkoutOf(string $path): ?array
    {
        foreach (array_reverse(self::$checkouts) as $checkout) {
            if (self::isBelow($path, $checkout['dir']) || self::isBelow($path, $checkout['real'])) {
                return $checkout;
            }
        }

        return null;
    }

    /** @return array{dir: string, real: string} */
    private static function checkout(string $dir): array
    {
        $dir = rtrim(self::absolute($dir), '/');

        return ['dir' => $dir, 'real' => realpath($dir) ?: $dir];
    }

    private static function isBelow(string $path, string $dir): bool
    {
        return str_starts_with($path, rtrim($dir, '/').'/');
    }

    /**
     * The path the kernel holds for $handle: the entry in /proc/self/fd that
     * is the same file. Null when none is.
     *
     * @param  resource  $handle
     */
    private static function openedPath($handle): ?string
    {
        $file = fstat($handle);
        foreach (@scandir(self::OPEN_FILES) ?: [] as $fd) {
            $entry = self::OPEN_FILES.'/'.$fd;
            $candidate = ctype_digit($fd) ? @stat($entry) : false;
            if ($file !== false && $candidate !== false && [$candidate['dev'], $candidate['ino']] === [$file['dev'], $file['ino']]) {
                return @readlink($entry) ?: null;
            }
        }

        return null;
    }

    /** The account whose home $path is in, by its first segment below the homes, or null. */
    private static function accountOf(string $path): ?string
    {
        $homes = rtrim(self::$homesDir ?? EnginePaths::HOMES_DIR, '/').'/';
        if (! str_starts_with($path, $homes)) {
            return null;
        }
        $account = current(array_filter(explode('/', substr($path, strlen($homes))), static fn (string $part): bool => $part !== ''));

        return in_array($account, [false, '.', '..'], true) ? null : $account;
    }

    private static function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : rtrim((string) getcwd(), '/').'/'.$path;
    }
}
