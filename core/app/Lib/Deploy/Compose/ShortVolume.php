<?php

namespace App\Lib\Deploy\Compose;

/**
 * A short-syntax volume entry, once interpolated, read as Compose's own parser
 * reads it: every `:` separates fields, inside braces and brackets too, except
 * the one after a lone drive letter. An entry of one field, or of two characters
 * at most, is an anonymous volume and has no source.
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
