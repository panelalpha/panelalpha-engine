<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ServerMetrics',
    properties: [
        new OA\Property(property: 'cpu_usage_percent', type: 'number', format: 'float', example: 12.5, nullable: true, description: 'CPU busy now: the metrics collector\'s latest sample, or two /proc/stat reads when it is not running'),
        new OA\Property(property: 'ram_total', type: 'integer', example: 8126464, description: 'kB'),
        new OA\Property(property: 'ram_free', type: 'integer', example: 4063232, description: 'kB available (MemAvailable)'),
        new OA\Property(property: 'ram_used', type: 'integer', example: 4063232, description: 'kB'),
        new OA\Property(property: 'ram_usage_percent', type: 'number', format: 'float', example: 50.0),
        new OA\Property(property: 'disk_path', type: 'string', example: '/home', description: 'The host disk measured: the one every project keeps its files and images on'),
        new OA\Property(property: 'disk_total', type: 'integer', example: 160982630400, nullable: true, description: 'Bytes'),
        new OA\Property(property: 'disk_free', type: 'integer', example: 51514441728, nullable: true, description: 'Bytes'),
        new OA\Property(property: 'disk_used', type: 'integer', example: 109468188672, nullable: true, description: 'Bytes'),
        new OA\Property(property: 'disk_usage_percent', type: 'number', format: 'float', example: 68.0, nullable: true, description: '(total - available) / total. Counts the root reserve as used, so it reads a little above df'),
        new OA\Property(
            property: 'memory_budget',
            description: 'How much memory one project may have, in MB.',
            properties: [
                new OA\Property(property: 'total_mb', type: 'integer', example: 3809, description: 'The server\'s RAM'),
                new OA\Property(property: 'engine_mb', type: 'integer', example: 512, description: 'Kept for the engine: DEPLOY_ENGINE_MEMORY, 512 when unset'),
                new OA\Property(property: 'max_project_mb', type: 'integer', example: 3297, description: 'The largest memory_limit any project may have: total_mb less engine_mb'),
                new OA\Property(property: 'default_project_mb', type: 'integer', example: 3297, description: 'The memory_limit a project gets when created without one'),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
class ServerMetricsSchema {}
