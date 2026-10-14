<?php

namespace App\Lib\Project;

use Illuminate\Support\Carbon;

/**
 * A point in a log, as the log endpoints take it: Docker's --since/--until
 * form, an RFC 3339 time or a duration back from now.
 */
final class LogTime
{
    /** \z, since $ also matches before a final newline. */
    public const PATTERN = '/^(\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:\d{2}))?|(\d+(ms|s|m|h))+)\z/';

    public const EXPECTED = 'an RFC 3339 time (2026-10-03T12:00:00Z) or a duration back from now (10m, 2h, 1h30m)';

    private const DURATION = '/^(\d+(ms|s|m|h))+\z/';

    private const UNIT_MILLISECONDS = ['ms' => 1, 's' => 1000, 'm' => 60000, 'h' => 3600000];

    public static function isDuration(string $value): bool
    {
        return preg_match(self::DURATION, $value) === 1;
    }

    /** The instant a time names, or a duration counts back to from now. */
    public static function instant(string $value): Carbon
    {
        if (!self::isDuration($value)) {
            return Carbon::parse($value);
        }

        preg_match_all('/(\d+)(ms|s|m|h)/', $value, $parts, PREG_SET_ORDER);
        $milliseconds = 0;
        foreach ($parts as [, $amount, $unit]) {
            $milliseconds += (int) $amount * self::UNIT_MILLISECONDS[$unit];
        }

        return Carbon::now()->subMilliseconds($milliseconds);
    }
}
