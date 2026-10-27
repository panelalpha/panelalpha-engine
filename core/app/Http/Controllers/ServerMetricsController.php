<?php

namespace App\Http\Controllers;

use App\Http\Requests\MetricsRangeRequest;
use App\Lib\Host\DataDisk;
use App\Lib\Host\HostMemoryProbe;
use App\Lib\Host\ProjectMemory;
use App\Lib\Host\ServerMetricsHistory;
use Carbon\CarbonPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class ServerMetricsController extends Controller
{
    /** @var list<string> */
    private const BUCKET_METRICS = ServerMetricsHistory::SAMPLED_METRICS;

    /** A collector sample younger than this is "now". It writes every 5s by default. */
    private const FRESH_SAMPLE_SECONDS = 30;

    #[OA\Get(
        path: '/metrics/current',
        summary: 'Get current server metrics',
        security: [['bearerAuth' => []]],
        tags: ['Server Metrics'],
        responses: [
            new OA\Response(response: 200, description: 'Current metrics', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ServerMetrics')],
            )),
        ],
    )]
    public function current(): JsonResponse
    {
        $ramData = file_get_contents('/proc/meminfo');
        preg_match("/MemTotal:\s+(\d+) kB/", $ramData, $total);
        preg_match("/MemAvailable:\s+(\d+) kB/", $ramData, $available);

        $ramTotal = (int) $total[1];
        $ramFree = (int) $available[1];
        $ramUsed = $ramTotal - $ramFree;
        $ramUsagePercent = ($ramUsed / $ramTotal) * 100;

        $disk = DataDisk::usage();

        $memory = HostMemoryProbe::current();

        return new JsonResponse(['data' => [
            'cpu_usage_percent' => $this->cpuUsagePercent(),
            'ram_total' => $ramTotal,
            'ram_free' => $ramFree,
            'ram_used' => $ramUsed,
            'ram_usage_percent' => $ramUsagePercent,
            'disk_path' => DataDisk::PATH,
            'disk_total' => $disk['total'] ?? null,
            'disk_free' => $disk['free'] ?? null,
            'disk_used' => $disk['used'] ?? null,
            'disk_usage_percent' => $disk['percent'] ?? null,
            // How much one project may have.
            'memory_budget' => $memory->toArray()
                + ['default_project_mb' => ProjectMemory::defaultMb($memory)],
        ]]);
    }

    /**
     * The collector's latest sample when it is recent, otherwise two reads of
     * /proc/stat a moment apart. One read alone is the average since boot.
     */
    private function cpuUsagePercent(): ?float
    {
        $since = Carbon::now('UTC')->subSeconds(self::FRESH_SAMPLE_SECONDS)->format('Y-m-d H:i:s');
        // No collector table yet is no sample yet: measure here instead.
        try {
            $sampled = DB::table('server_metrics')
                ->where('timestamp', '>=', $since)
                ->orderByDesc('timestamp')
                ->value('cpu_percent');
        } catch (QueryException) {
            $sampled = null;
        }
        if (is_numeric($sampled)) {
            return round((float) $sampled, 2);
        }

        $before = (string) @file_get_contents('/proc/stat');
        usleep(250_000);
        $after = (string) @file_get_contents('/proc/stat');

        return self::cpuPercentBetween($before, $after);
    }

    /** Busy share of the ticks that passed between two /proc/stat reads. */
    public static function cpuPercentBetween(string $before, string $after): ?float
    {
        $a = self::cpuTicks($before);
        $b = self::cpuTicks($after);
        if ($a === null || $b === null) {
            return null;
        }

        $total = $b['total'] - $a['total'];
        if ($total <= 0) {
            return null;
        }
        $idle = $b['idle'] - $a['idle'];

        return round(max(0, min(100, ($total - $idle) / $total * 100)), 2);
    }

    /**
     * @return ?array{total: int, idle: int}
     */
    private static function cpuTicks(string $stat): ?array
    {
        // user nice system idle iowait irq softirq steal; guest is already in user
        if (preg_match('/^cpu\s+((?:\d+\s*){4,})/m', $stat, $m) !== 1) {
            return null;
        }
        $f = array_map('intval', preg_split('/\s+/', trim($m[1])) ?: []);
        $f = array_pad(array_slice($f, 0, 8), 8, 0);

        return ['total' => array_sum($f), 'idle' => $f[3] + $f[4]];
    }

    #[OA\Get(
        path: '/metrics/last-hour-averages',
        summary: 'Get last hour metric averages',
        security: [['bearerAuth' => []]],
        tags: ['Server Metrics'],
        responses: [
            new OA\Response(response: 200, description: 'Last hour averages', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'avg_cpu_percent', type: 'number', format: 'float'),
                    new OA\Property(property: 'avg_ram_percent', type: 'number', format: 'float'),
                ])],
            )),
        ],
    )]
    public function lastHourAverages(): JsonResponse
    {
        /** @var object{avg_cpu_percent: ?float, avg_ram_percent: ?float} $result */
        $result = $this->lastHourAveragesQuery()->first();

        return new JsonResponse(['data' => [
            'avg_cpu_percent' => $result->avg_cpu_percent,
            'avg_ram_percent' => $result->avg_ram_percent,
        ]]);
    }

    // split out from lastHourAverages() so tests can inspect the built SQL
    // (via ->toSql()) without running it against a live driver
    private function lastHourAveragesQuery(): Builder
    {
        // bind the cutoff instead of NOW() - INTERVAL so this works on sqlite too
        $since = Carbon::now('UTC')->subHour()->format('Y-m-d H:i:s');

        return DB::table('server_metrics')
            ->where('timestamp', '>=', $since)
            ->selectRaw('AVG(cpu_percent) AS avg_cpu_percent, AVG(ram_percent) AS avg_ram_percent');
    }

    #[OA\Get(
        path: '/metrics/last-5-minutes',
        summary: 'Get metrics for the last 5 minutes',
        security: [['bearerAuth' => []]],
        tags: ['Server Metrics'],
        responses: [
            new OA\Response(response: 200, description: 'Last 5 minutes metrics', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServerMetricsPeriod'))],
            )),
        ],
    )]
    public function last5Minutes(): JsonResponse
    {
        $bucketSeconds = 5;
        $end = $this->floorToNearestSeconds(Carbon::now('UTC')->subSeconds($bucketSeconds), $bucketSeconds);
        $start = $end->copy()->subSeconds(295);

        return $this->buildMetricsResponse($start, $end, $bucketSeconds);
    }

    #[OA\Get(
        path: '/metrics/last-hour',
        summary: 'Get metrics for the last hour',
        security: [['bearerAuth' => []]],
        tags: ['Server Metrics'],
        responses: [
            new OA\Response(response: 200, description: 'Last hour metrics', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServerMetricsPeriod'))],
            )),
        ],
    )]
    public function lastHour(): JsonResponse
    {
        $bucketSeconds = 60;
        $end = $this->floorToNearestSeconds(Carbon::now('UTC')->subSeconds($bucketSeconds), $bucketSeconds);
        $start = $end->copy()->subMinutes(59);

        return $this->buildMetricsResponse($start, $end, $bucketSeconds);
    }

    #[OA\Get(
        path: '/metrics/last-12-hours',
        summary: 'Get metrics for the last 12 hours',
        security: [['bearerAuth' => []]],
        tags: ['Server Metrics'],
        responses: [
            new OA\Response(response: 200, description: 'Last 12 hours metrics', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServerMetricsPeriod'))],
            )),
        ],
    )]
    public function last12Hours(): JsonResponse
    {
        $bucketSeconds = 12 * 60;
        $end = $this->floorToNearestSeconds(Carbon::now('UTC')->subSeconds($bucketSeconds), $bucketSeconds);
        $start = $end->copy()->subSeconds(59 * $bucketSeconds);

        return $this->buildMetricsResponse($start, $end, $bucketSeconds);
    }

    #[OA\Get(
        path: '/metrics/history',
        summary: 'Get averaged server metrics over 1 hour, 24 hours, 7 days or 30 days',
        description: 'Averaged in the database, so 30 days costs about what 1 hour does. Buckets: 1h = 1 minute, '
            .'24h = 10 minutes, 7d = 1 hour, 30d = 6 hours. The last bucket is the last complete one. A bucket '
            .'with no samples carries only its period. disk_percent is the disk holding /home, and is null for '
            .'samples taken before it was recorded.',
        security: [['bearerAuth' => []]],
        tags: ['Server Metrics'],
        parameters: [
            new OA\Parameter(name: 'range', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['1h', '24h', '7d', '30d'], default: '24h')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Metrics history', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServerMetricsPeriod')),
                    new OA\Property(property: 'meta', type: 'object', properties: [
                        new OA\Property(property: 'range', type: 'string', example: '24h'),
                        new OA\Property(property: 'bucket_seconds', type: 'integer', example: 600),
                        new OA\Property(property: 'from', type: 'string', example: '2026-09-27 12:10:00', description: 'UTC, first bucket start'),
                        new OA\Property(property: 'to', type: 'string', example: '2026-09-28 12:00:00', description: 'UTC, last bucket start'),
                    ]),
                ],
            )),
            new OA\Response(response: 422, description: 'Unknown range', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function history(MetricsRangeRequest $request): JsonResponse
    {
        return new JsonResponse(ServerMetricsHistory::for($request->range()));
    }

    private function floorToNearestSeconds(Carbon $dt, int $seconds): Carbon
    {
        $timestamp = (int) $dt->timestamp;
        $floored = $timestamp - ($timestamp % $seconds);

        return Carbon::createFromTimestampUTC($floored);
    }

    private function buildMetricsResponse(Carbon $start, Carbon $end, int $bucketSeconds): JsonResponse
    {
        // plain select + PHP-side bucketing instead of FROM_UNIXTIME/UNIX_TIMESTAMP
        // (MySQL-only): portable across drivers, and the WHERE already bounds the
        // row count to this window, so grouping in PHP is cheap.
        $rows = $this->rawMetricsQuery($start)->get();

        $sums = [];
        $counts = [];
        foreach ($rows as $row) {
            $bucketTimestamp = (int) floor(Carbon::parse($row->timestamp, 'UTC')->timestamp / $bucketSeconds) * $bucketSeconds;
            $bucketKey = Carbon::createFromTimestampUTC($bucketTimestamp)->format('Y-m-d H:i:s');
            $counts[$bucketKey] = ($counts[$bucketKey] ?? 0) + 1;
            foreach (self::BUCKET_METRICS as $metric) {
                $sums[$bucketKey][$metric] = ($sums[$bucketKey][$metric] ?? 0) + (float) $row->$metric;
            }
        }

        $dataByPeriod = [];
        foreach ($sums as $bucketKey => $metricSums) {
            foreach (self::BUCKET_METRICS as $metric) {
                $dataByPeriod[$bucketKey][$metric] = round($metricSums[$metric] / $counts[$bucketKey], 2);
            }
        }

        $format = 'Y-m-d H:i:s';
        $data = [];
        $period = CarbonPeriod::create($start, "{$bucketSeconds} seconds", $end);

        foreach ($period as $dt) {
            /** @var Carbon $dt */
            $key = $dt->format($format);
            $periodData = ['period' => $key];
            if (array_key_exists($key, $dataByPeriod)) {
                foreach ($dataByPeriod[$key] as $metric => $value) {
                    $periodData[$metric] = $value;
                }
            }
            $data[] = $periodData;
        }

        return new JsonResponse(['data' => $data]);
    }

    // split out from buildMetricsResponse() so tests can inspect the built SQL
    // (via ->toSql()) without running it against a live driver
    private function rawMetricsQuery(Carbon $start): Builder
    {
        return DB::table('server_metrics')
            ->select(array_merge(['timestamp'], self::BUCKET_METRICS))
            ->where('timestamp', '>=', $start->format('Y-m-d H:i:s'));
    }
}
