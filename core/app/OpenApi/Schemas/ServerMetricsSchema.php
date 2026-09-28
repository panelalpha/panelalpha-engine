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
            description: 'Where the RAM goes, and how much projects may have, in MB. Measured live from the host.',
            properties: [
                new OA\Property(property: 'total_mb', type: 'integer', example: 3809, description: 'The server\'s RAM'),
                new OA\Property(property: 'engine_mb', type: 'integer', example: 481, description: 'What the engine\'s own containers hold'),
                new OA\Property(property: 'system_mb', type: 'integer', example: 900, description: 'Kernel, Docker and the OS: in use, neither engine nor projects'),
                new OA\Property(property: 'projects_used_mb', type: 'integer', example: 0, description: 'What all projects hold now'),
                new OA\Property(property: 'headroom_mb', type: 'integer', example: 256, description: 'Kept free for the engine to grow during deploys'),
                new OA\Property(property: 'projects_pool_mb', type: 'integer', example: 2172, description: 'What all projects together may use: DEPLOY_PROJECTS_MEMORY, or total minus engine, system and headroom'),
                new OA\Property(property: 'projects_pool_source', type: 'string', enum: ['measured', 'configured']),
                new OA\Property(property: 'free_for_projects_mb', type: 'integer', example: 2172, description: 'What a new project may take right now; creation fails above this'),
                new OA\Property(property: 'max_project_mb', type: 'integer', example: 2172, description: 'The largest memory_limit any project may have'),
                new OA\Property(property: 'default_project_mb', type: 'integer', example: 4096, description: 'The memory_limit a project gets when created without one'),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
class ServerMetricsSchema
{
}
