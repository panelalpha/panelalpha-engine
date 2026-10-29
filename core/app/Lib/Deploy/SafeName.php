<?php

namespace App\Lib\Deploy;

use InvalidArgumentException;

/**
 * Names that are about to become part of a filesystem path or a command's
 * argv.
 *
 * Account usernames reach the engine from the database and end up in a log
 * directory, a cache directory, a volume name and half a dozen sudo commands.
 * Each of those sites used to carry its own copy of the same regex, and the
 * copies shared two holes:
 *
 *   - PHP's `$` matches immediately before a final newline, so "acme\n"
 *     passed every one of them;
 *   - `.` and `..` are made only of accepted characters, and both resolve
 *     somewhere other than the account's own directory.
 *
 * The rule lives here once so a fix reaches every caller.
 *
 * No Laravel dependencies — unit-testable with a string.
 */
final class SafeName
{
    /** `\z`, not `$`: `$` would also match before a trailing newline. */
    private const PATTERN = '/^[a-zA-Z0-9_.-]+\z/';

    /** A name of nothing but dots resolves outside its own directory. */
    private const DOTS_ONLY = '/^\.+\z/';

    public static function isSafe(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1
            && preg_match(self::DOTS_ONLY, $name) !== 1;
    }

    /**
     * @param  string  $subject  what the name is, for the message — "username
     *                           for Docker cleanup", "deploy id"
     *
     * @throws InvalidArgumentException
     */
    public static function assert(string $name, string $subject): void
    {
        if (! self::isSafe($name)) {
            throw new InvalidArgumentException("Invalid {$subject}: {$name}");
        }
    }
}
