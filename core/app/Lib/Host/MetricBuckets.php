<?php

namespace App\Lib\Host;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The history ranges and buckets GET /metrics/history uses, for the project
 * histories, so a project chart lines up with the server chart.
 */
final class MetricBuckets
{
    /** range => [span, bucket] in seconds. */
    public const RANGES = [
        '1h' => [3600, 60],
        '24h' => [86400, 600],
        '7d' => [604800, 3600],
        '30d' => [2592000, 21600],
    ];

    public const DEFAULT_RANGE = '24h';

    /**
     * The last bucket is the last complete one.
     *
     * @return array{start: Carbon, end: Carbon, bucket: int}
     */
    public static function window(string $range, ?Carbon $now = null): array
    {
        [$span, $bucket] = self::RANGES[$range];
        $at = ($now ?? Carbon::now('UTC'))->copy()->setTimezone('UTC')->subSeconds($bucket);
        $end = Carbon::createFromTimestampUTC($at->timestamp - ($at->timestamp % $bucket));

        return ['start' => $end->copy()->subSeconds($span - $bucket), 'end' => $end, 'bucket' => $bucket];
    }

    /** The bucket start, in seconds since the epoch, of the `timestamp` column. */
    public static function sql(int $bucket): string
    {
        $column = DB::connection()->getQueryGrammar()->wrap('timestamp');

        return match (DB::connection()->getDriverName()) {
            'sqlite' => "(CAST(strftime('%s', {$column}) AS INTEGER) / {$bucket}) * {$bucket}",
            default => "(TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', {$column}) DIV {$bucket}) * {$bucket}",
        };
    }

    /**
     * Every bucket of the window as `['period' => 'Y-m-d H:i:s', ...]`; a
     * bucket without samples carries only its period.
     *
     * @param  array<int, array<string, mixed>>  $byBucket  bucket start => values
     * @return list<array<string, mixed>>
     */
    public static function periods(Carbon $start, Carbon $end, int $bucket, array $byBucket): array
    {
        $periods = [];
        for ($t = (int) $start->timestamp; $t <= (int) $end->timestamp; $t += $bucket) {
            $periods[] = ['period' => Carbon::createFromTimestampUTC($t)->format('Y-m-d H:i:s')] + ($byBucket[$t] ?? []);
        }

        return $periods;
    }
}
