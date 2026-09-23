<?php

namespace App\Http\Controllers;

use Carbon\CarbonPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class ServerMetricsController extends Controller
{
    /** @var list<string> */
    private const BUCKET_METRICS = [
        'cpu_percent', 'cpu_load_avg_1', 'cpu_load_avg_5', 'cpu_load_avg_15',
        'ram_percent', 'swap_percent', 'disk_read_bps', 'disk_write_bps',
        'disk_read_iops', 'disk_write_iops', 'net_in_bps', 'net_out_bps',
        'net_in_pps', 'net_out_pps',
    ];

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
        $ramData = file_get_contents("/proc/meminfo");
        preg_match("/MemTotal:\s+(\d+) kB/", $ramData, $total);
        preg_match("/MemAvailable:\s+(\d+) kB/", $ramData, $available);

        $ramTotal = (int)$total[1];
        $ramFree = (int)$available[1];
        $ramUsed = $ramTotal - $ramFree;
        $ramUsagePercent = ($ramUsed / $ramTotal) * 100;

        $diskTotal = disk_total_space("/");
        $diskFree = disk_free_space("/");
        $diskUsed = $diskTotal - $diskFree;
        $diskUsagePercent = ($diskUsed / $diskTotal) * 100;

        return new JsonResponse(['data' => [
            'cpu_usage_percent' => $this->getCpuUsage(),
            'ram_total' => $ramTotal,
            'ram_free' => $ramFree,
            'ram_used' => $ramUsed,
            'ram_usage_percent' => $ramUsagePercent,
            'disk_total' => $diskTotal,
            'disk_free' => $diskFree,
            'disk_used' => $diskUsed,
            'disk_usage_percent' => $diskUsagePercent,
        ]]);
    }

    private function getCpuUsage(): int|float
    {
        $cpuData = file_get_contents('/proc/stat');
        $lines = explode("\n", $cpuData);
        $cpuLine = preg_split('/\s+/', $lines[0]);
        $user = (int)$cpuLine[1];
        $nice = (int)$cpuLine[2];
        $system = (int)$cpuLine[3];
        $idle = (int)$cpuLine[4];
        $total = $user + $nice + $system + $idle;
        $idleTime = $idle;
        $cpuUsage = (($total - $idleTime) / $total) * 100;
        return $cpuUsage;
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
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServerMetrics'))],
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
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServerMetrics'))],
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
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServerMetrics'))],
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

    private function floorToNearestSeconds(Carbon $dt, int $seconds): Carbon
    {
        $timestamp = (int)$dt->timestamp;
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
            $bucketTimestamp = (int)floor(Carbon::parse($row->timestamp, 'UTC')->timestamp / $bucketSeconds) * $bucketSeconds;
            $bucketKey = Carbon::createFromTimestampUTC($bucketTimestamp)->format('Y-m-d H:i:s');
            $counts[$bucketKey] = ($counts[$bucketKey] ?? 0) + 1;
            foreach (self::BUCKET_METRICS as $metric) {
                $sums[$bucketKey][$metric] = ($sums[$bucketKey][$metric] ?? 0) + (float)$row->$metric;
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
                foreach($dataByPeriod[$key] as $metric => $value) {
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
