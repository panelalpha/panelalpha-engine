<?php

namespace App\Lib\Deploy;

use InvalidArgumentException;

/**
 * Names about to become part of a filesystem path or argv, under one rule that also refuses a trailing
 * newline (which `$` lets through) and names of only dots. No Laravel dependencies.
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
