<?php

namespace App\System\Privileged;

/**
 * A list goes to the broker as its count, then its items, as rootbroker/actions/sequence.py reads it.
 *
 * @internal
 */
final class ActionValues
{
    /**
     * `count, key, value, key, value, …` in the map's order.
     *
     * @param  array<array-key, string>  $map
     * @return list<string>
     */
    public static function pairs(array $map): array
    {
        $values = [(string) count($map)];
        foreach ($map as $key => $value) {
            array_push($values, (string) $key, $value);
        }

        return $values;
    }

    /**
     * Puts $pair first in the pair list whose count is at $at.
     *
     * @param  list<string>  $values
     * @param  array{string, string}  $pair
     * @return list<string>
     */
    public static function withPairInFront(array $values, int $at, array $pair): array
    {
        if (! isset($values[$at]) || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $values[$at]) !== 1) {
            throw InvalidArgument::of('value count', $values[$at] ?? '');
        }

        return [
            ...array_slice($values, 0, $at),
            (string) ((int) $values[$at] + 1),
            ...$pair,
            ...array_slice($values, $at + 1),
        ];
    }
}
