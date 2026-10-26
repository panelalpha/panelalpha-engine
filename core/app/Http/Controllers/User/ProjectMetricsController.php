<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Lib\Host\MetricBuckets;
use App\Lib\Host\ProjectStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * What a project uses, from the samples `metrics:projects:sample` takes every
 * minute: the project's DinD container as a whole, and each container inside it.
 */
class ProjectMetricsController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/metrics/history',
        summary: 'Get a project\'s CPU and memory over 1 hour, 24 hours, 7 days or 30 days',
        description: 'The project\'s DinD container as a whole: its applications and its own Docker daemon. '
            .'Sampled every minute; the buckets are those of GET /metrics/history (1h = 1 minute, 24h = 10 minutes, '
            .'7d = 1 hour, 30d = 6 hours). 7d and 30d read hourly averages, which appear a few minutes after '
            .'each hour. cpu_percent counts one core as 100. memory_bytes leaves out page cache the kernel can '
            .'drop at once, as `docker stats` does. A bucket with no samples carries only its period.',
        security: [['bearerAuth' => []]],
        tags: ['Usage'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'range', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['1h', '24h', '7d', '30d'], default: '24h')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Project metrics history', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'period', type: 'string', example: '2026-10-01 09:00:00', description: 'UTC, bucket start'),
                        new OA\Property(property: 'cpu_percent', type: 'number', format: 'float', nullable: true),
                        new OA\Property(property: 'memory_bytes', type: 'integer', nullable: true),
                        new OA\Property(property: 'memory_limit_bytes', type: 'integer', nullable: true),
                    ], type: 'object')),
                    new OA\Property(property: 'meta', type: 'object', properties: [
                        new OA\Property(property: 'range', type: 'string'),
                        new OA\Property(property: 'bucket_seconds', type: 'integer'),
                        new OA\Property(property: 'from', type: 'string'),
                        new OA\Property(property: 'to', type: 'string'),
                    ]),
                ],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Unknown range', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function history(Request $request, string $username): JsonResponse
    {
        $project = $this->projectOr404($username);
        $range = $this->range($request);
        $window = MetricBuckets::window($range);

        $rows = DB::table(self::table('project_metrics', $window['bucket']))
            ->selectRaw(MetricBuckets::sql($window['bucket']).' AS bucket, AVG(cpu_percent) AS cpu_percent, '
                .'AVG(memory_bytes) AS memory_bytes, MAX(memory_limit_bytes) AS memory_limit_bytes')
            ->where('username', $project->username)
            ->where('timestamp', '>=', $window['start']->format('Y-m-d H:i:s'))
            ->where('timestamp', '<', $window['end']->copy()->addSeconds($window['bucket'])->format('Y-m-d H:i:s'))
            ->groupByRaw('bucket')
            ->get();

        $byBucket = [];
        foreach ($rows as $row) {
            $byBucket[(int) $row->bucket] = self::values($row);
        }

        return new JsonResponse([
            'data' => MetricBuckets::periods($window['start'], $window['end'], $window['bucket'], $byBucket),
            'meta' => self::meta($range, $window),
        ]);
    }

    #[OA\Get(
        path: '/projects/{username}/containers/metrics',
        summary: 'Get CPU and memory of each container inside a project',
        description: 'Without range: each container\'s latest sample (taken every minute). With range: each '
            .'container\'s history in the buckets of GET /metrics/history. cpu_percent counts one core as 100; '
            .'memory_bytes leaves out page cache the kernel can drop at once, as `docker stats` does. '
            .'memory_limit_bytes is null when the container has no limit of its own.',
        security: [['bearerAuth' => []]],
        tags: ['Usage'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'range', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['1h', '24h', '7d', '30d'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Container metrics', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'container_id', type: 'string'),
                        new OA\Property(property: 'name', type: 'string', nullable: true, example: 'project-app-1'),
                        new OA\Property(property: 'service', type: 'string', nullable: true, example: 'app'),
                        new OA\Property(property: 'cpu_percent', type: 'number', format: 'float', nullable: true, description: 'Without range'),
                        new OA\Property(property: 'memory_bytes', type: 'integer', nullable: true, description: 'Without range'),
                        new OA\Property(property: 'memory_limit_bytes', type: 'integer', nullable: true, description: 'Without range'),
                        new OA\Property(property: 'sampled_at', type: 'string', description: 'Without range'),
                        new OA\Property(property: 'history', type: 'array', description: 'With range: period, cpu_percent, memory_bytes, memory_limit_bytes', items: new OA\Items(type: 'object')),
                    ], type: 'object')),
                ],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Unknown range', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function containers(Request $request, string $username): JsonResponse
    {
        $project = $this->projectOr404($username);

        if (! $request->has('range')) {
            return new JsonResponse(['data' => $this->latestContainers($project->username)]);
        }

        $range = $this->range($request);
        $window = MetricBuckets::window($range);
        $rows = DB::table(self::table('project_container_metrics', $window['bucket']))
            ->selectRaw('container_id, '.MetricBuckets::sql($window['bucket']).' AS bucket, AVG(cpu_percent) AS cpu_percent, '
                .'AVG(memory_bytes) AS memory_bytes, MAX(memory_limit_bytes) AS memory_limit_bytes, MAX(name) AS name, MAX(service) AS service')
            ->where('username', $project->username)
            ->where('timestamp', '>=', $window['start']->format('Y-m-d H:i:s'))
            ->where('timestamp', '<', $window['end']->copy()->addSeconds($window['bucket'])->format('Y-m-d H:i:s'))
            ->groupBy('container_id')
            ->groupByRaw('bucket')
            ->get();

        $containers = [];
        foreach ($rows as $row) {
            $id = (string) $row->container_id;
            $containers[$id]['name'] ??= $row->name;
            $containers[$id]['service'] ??= $row->service;
            $containers[$id]['buckets'][(int) $row->bucket] = self::values($row);
        }

        $data = [];
        foreach ($containers as $id => $c) {
            $data[] = [
                'container_id' => $id,
                'name' => $c['name'],
                'service' => $c['service'],
                'history' => MetricBuckets::periods($window['start'], $window['end'], $window['bucket'], $c['buckets']),
            ];
        }

        return new JsonResponse(['data' => $data, 'meta' => self::meta($range, $window)]);
    }

    /** @return list<array<string, mixed>> */
    private function latestContainers(string $username): array
    {
        $since = Carbon::now('UTC')->subSeconds(ProjectStats::STALE_SECONDS)->format('Y-m-d H:i:s');
        $latest = DB::table('project_container_metrics')
            ->where('username', $username)
            ->where('timestamp', '>=', $since)
            ->max('timestamp');
        if ($latest === null) {
            return [];
        }

        return DB::table('project_container_metrics')
            ->where('username', $username)
            ->where('timestamp', $latest)
            ->orderBy('name')
            ->get()
            ->map(fn (object $row): array => [
                'container_id' => (string) $row->container_id,
                'name' => $row->name,
                'service' => $row->service,
                'cpu_percent' => $row->cpu_percent === null ? null : round((float) $row->cpu_percent, 2),
                'memory_bytes' => $row->memory_bytes === null ? null : (int) $row->memory_bytes,
                'memory_limit_bytes' => $row->memory_limit_bytes === null ? null : (int) $row->memory_limit_bytes,
                'sampled_at' => Carbon::parse((string) $row->timestamp, 'UTC')->toIso8601ZuluString(),
            ])
            ->all();
    }

    /**
     * Minute rows are kept two days and hourly averages the month, so a
     * bucket of an hour or more reads the averages.
     */
    private static function table(string $minutes, int $bucket): string
    {
        return $bucket >= 3600 ? $minutes.'_hourly' : $minutes;
    }

    private function range(Request $request): string
    {
        return $request->validate([
            'range' => ['sometimes', 'string', Rule::in(array_keys(MetricBuckets::RANGES))],
        ])['range'] ?? MetricBuckets::DEFAULT_RANGE;
    }

    /** @return array{cpu_percent: ?float, memory_bytes: ?int, memory_limit_bytes: ?int} */
    private static function values(object $row): array
    {
        return [
            'cpu_percent' => $row->cpu_percent === null ? null : round((float) $row->cpu_percent, 2),
            'memory_bytes' => $row->memory_bytes === null ? null : (int) round((float) $row->memory_bytes),
            'memory_limit_bytes' => $row->memory_limit_bytes === null ? null : (int) $row->memory_limit_bytes,
        ];
    }

    /**
     * @param  array{start: Carbon, end: Carbon, bucket: int}  $window
     * @return array{range: string, bucket_seconds: int, from: string, to: string}
     */
    private static function meta(string $range, array $window): array
    {
        return [
            'range' => $range,
            'bucket_seconds' => $window['bucket'],
            'from' => $window['start']->format('Y-m-d H:i:s'),
            'to' => $window['end']->format('Y-m-d H:i:s'),
        ];
    }
}
