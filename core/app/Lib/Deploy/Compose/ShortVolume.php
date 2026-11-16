<?php

namespace App\Lib\Deploy\Compose;

/**
 * An interpolated short-syntax volume read as Compose reads it: every `:` splits, in braces too,
 * except after a lone drive letter; one field, or two characters at most, is an anonymous volume.
 */
final class ShortVolume
{
    /** The host path or volume name Compose mounts, or null for an anonymous volume. */
    public static function source(string $entry): ?string
    {
        $fields = self::fields($entry);

        return count($fields) > 1 ? $fields[0] : null;
    }

    /** A Windows drive or UNC path: Compose binds it, and it is never a path in the checkout. */
    public static function isWindowsPath(string $source): bool
    {
        return str_starts_with($source, '\\\\') || preg_match('/^\p{L}:/u', $source) === 1;
    }

    /** @return list<string> */
    private static function fields(string $entry): array
    {
        if (strlen($entry) <= 2) {
            return [$entry];
        }
        $fields = [];
        $field = '';
        foreach (str_split($entry) as $char) {
            if ($char === ':' && ! self::isDriveLetter($field)) {
                $fields[] = $field;
                $field = '';

                continue;
            }
            $field .= $char;
        }
        $fields[] = $field;

        return $fields;
    }

    private static function isDriveLetter(string $field): bool
    {
        return preg_match('/^\p{L}$/u', $field) === 1;
    }
}
