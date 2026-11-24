<?php

namespace App\Lib\Hub;

/** A value cut to what the Hub accepts, or null: the Hub refuses a whole request over one value out of its range. */
final class HubValues
{
    public static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    public static function count(mixed $value, ?int $max = null): ?int
    {
        if (! is_numeric($value) || $value < 0) {
            return null;
        }
        $count = (int) round((float) $value);

        return $max !== null && $count > $max ? null : $count;
    }

    public static function percent(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return round(min(100.0, max(0.0, (float) $value)), 2);
    }

    public static function share(mixed $value): ?float
    {
        return is_numeric($value) && $value >= 0 ? round((float) $value, 2) : null;
    }
}
