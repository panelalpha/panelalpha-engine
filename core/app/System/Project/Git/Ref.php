<?php

namespace App\System\Project\Git;

/**
 * Validates git ref / branch names without shelling out.
 * Mirrors the spirit of `git check-ref-format --allow-onelevel`.
 */
final class Ref
{
    public static function isValidName(string $name): bool
    {
        if ($name === '' || $name === '@') {
            return false;
        }
        if ($name[0] === '-' || str_starts_with($name, '.')) {
            return false;
        }
        if (str_contains($name, '..') || str_contains($name, '@{')) {
            return false;
        }
        // Control chars, space, tilde, caret, colon, question, asterisk, bracket, backslash
        if (preg_match('/[\x00-\x20~^:?*\[\\\\]/', $name) === 1) {
            return false;
        }
        if (str_ends_with($name, '.') || str_ends_with($name, '.lock')) {
            return false;
        }
        if (str_contains($name, '//') || str_starts_with($name, '/') || str_ends_with($name, '/')) {
            return false;
        }

        return true;
    }

    public static function assertName(string $name): void
    {
        if (! self::isValidName($name)) {
            throw new Exception('Invalid git ref name.', 422);
        }
    }
}
