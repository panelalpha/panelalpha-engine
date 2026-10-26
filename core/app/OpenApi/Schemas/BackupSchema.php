<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Backup',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'username', type: 'string', example: 'myproject'),
        new OA\Property(property: 'container_id', type: 'integer', example: 1),
        new OA\Property(property: 'kind', type: 'string', enum: ['manual', 'scheduled'], example: 'manual'),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'contents', type: 'object', nullable: true,
            properties: [
                new OA\Property(property: 'files', type: 'boolean'),
                new OA\Property(property: 'databases', type: 'boolean'),
            ]),
        new OA\Property(property: 'async_status', type: 'object', nullable: true),
        new OA\Property(property: 'error', type: 'string', nullable: true),
        new OA\Property(property: 'size_bytes', type: 'integer', example: 1048576),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'container', ref: '#/components/schemas/BackupContainer', nullable: true),
    ],
    type: 'object',
)]
class BackupSchema {}
