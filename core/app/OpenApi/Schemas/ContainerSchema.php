<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Container',
    properties: [
        new OA\Property(property: 'service', type: 'string', example: 'web'),
        new OA\Property(property: 'status', type: 'string', example: 'running', enum: ['running', 'stopped', 'exited', 'restarting']),
        new OA\Property(property: 'image', type: 'string', example: 'nginx:alpine', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ],
    type: 'object',
)]
class ContainerSchema {}
