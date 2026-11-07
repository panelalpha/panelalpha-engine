<?php

namespace App\Lib\Deploy\Compose;

/**
 * Values on their way into a generated compose file: a decision arrives as whatever JSON made of it,
 * and Compose refuses an integer where it wants a string, or a null in a list.
 */
final class ComposeValues
{
    /**
     * @param  mixed  $value
     * @return array<string, string> non-empty keys with scalar values, as strings
     */
    public static function stringMap($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && $key !== '' && (is_string($item) || is_int($item))) {
                $map[$key] = (string) $item;
            }
        }

        return $map;
    }

    /**
     * @param  mixed  $value
     * @return list<string> the non-empty strings in it
     */
    public static function stringList($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn ($item): bool => is_string($item) && $item !== ''
        ));
    }
}
