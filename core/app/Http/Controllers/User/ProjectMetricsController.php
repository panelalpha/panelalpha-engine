<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\MetricsRangeRequest;
use App\Lib\Host\ProjectStats;
use Illuminate\Http\JsonResponse;
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
    public function history(MetricsRangeRequest $request, string $username): JsonResponse
    {
        $project = $this->projectOr404($username);

        return new JsonResponse(ProjectStats::history($project->username, $request->range()));
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
    public function containers(MetricsRangeRequest $request, string $username): JsonResponse
    {
        $project = $this->projectOr404($username);

        if (! $request->has('range')) {
            return new JsonResponse(['data' => ProjectStats::latestContainers($project->username)]);
        }

        return new JsonResponse(ProjectStats::containerHistory($project->username, $request->range()));
    }
}
