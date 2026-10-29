<?php

namespace App\Lib\Deploy\Compose;

/**
 * Values on their way into a generated compose file.
 *
 * A decision crosses a process boundary as an array, so everything in it is
 * whatever JSON made of it. Compose is unforgiving about types — an integer
 * where it wants a string, a null in a list — and the generator should not be
 * the place that finds out.
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
