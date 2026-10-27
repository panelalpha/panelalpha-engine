<?php

namespace App\Lib\Host;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** The server's averaged metrics over a history range, in the buckets of {@see MetricBuckets}. */
final class ServerMetricsHistory
{
    /** @var list<string> What the collector writes on every sample. */
    public const SAMPLED_METRICS = [
        'cpu_percent', 'cpu_load_avg_1', 'cpu_load_avg_5', 'cpu_load_avg_15',
        'ram_percent', 'swap_percent', 'disk_read_bps', 'disk_write_bps',
        'disk_read_iops', 'disk_write_iops', 'net_in_bps', 'net_out_bps',
        'net_in_pps', 'net_out_pps',
    ];

    /** @var list<string> SAMPLED_METRICS plus what older rows lack. */
    private const METRICS = [...self::SAMPLED_METRICS, 'disk_percent'];

    /**
     * Nothing in a window changes until the next bucket completes, and 30 days
     * is ~0.6s of SQLite work, so each window is computed once.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{range: string, bucket_seconds: int, from: string, to: string}}
     */
    public static function for(string $range): array
    {
        $window = MetricBuckets::window($range);
        $data = Cache::remember(
            "metrics-history:{$range}:{$window['end']->timestamp}",
            $window['bucket'],
            fn (): array => self::periods($window)
        );

        return ['data' => $data, 'meta' => MetricBuckets::meta($range, $window)];
    }

    /**
     * @param  array{start: Carbon, end: Carbon, bucket: int}  $window
     * @return list<array<string, mixed>>
     */
    private static function periods(array $window): array
    {
        $byBucket = [];
        foreach (self::query($window['start'], $window['end']->copy()->addSeconds($window['bucket']), $window['bucket'])->get() as $row) {
            $byBucket[(int) $row->bucket] = self::values($row);
        }

        return MetricBuckets::periods($window['start'], $window['end'], $window['bucket'], $byBucket);
    }

    /** Grouped in SQL: 30 days of 5s samples is half a million rows, too many to bucket in PHP. */
    private static function query(Carbon $start, Carbon $end, int $bucket): Builder
    {
        $grammar = DB::connection()->getQueryGrammar();
        $averages = array_map(
            fn (string $metric): string => 'AVG('.$grammar->wrap($metric).') AS '.$grammar->wrap($metric),
            self::METRICS
        );

        return DB::table('server_metrics')
            ->selectRaw(MetricBuckets::sql($bucket).' AS bucket, '.implode(', ', $averages))
            ->where('timestamp', '>=', $start->format('Y-m-d H:i:s'))
            ->where('timestamp', '<', $end->format('Y-m-d H:i:s'))
            ->groupByRaw('bucket')
            ->orderBy('bucket');
    }

    /** @return array<string, ?float> */
    private static function values(object $row): array
    {
        $values = [];
        foreach (self::METRICS as $metric) {
            $value = $row->$metric;
            $values[$metric] = $value === null ? null : round((float) $value, 2);
        }

        return $values;
    }
}
