<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ServerMetrics',
    properties: [
        new OA\Property(property: 'cpu', type: 'number', format: 'float', example: 12.5, description: 'CPU usage in percent'),
        new OA\Property(property: 'memory', type: 'number', format: 'float', example: 45.2, description: 'Memory usage in percent'),
        new OA\Property(property: 'disk', type: 'number', format: 'float', example: 68.0, description: 'Disk usage in percent'),
        new OA\Property(property: 'load_avg', type: 'number', format: 'float', example: 0.85, nullable: true),
        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
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
class ServerMetricsSchema
{
}
