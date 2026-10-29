<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MysqlDatabase',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'user_id', type: 'integer', example: 1),
        new OA\Property(property: 'database', type: 'string', example: 'johndoe_wp'),
        new OA\Property(property: 'details', type: 'object', nullable: true),
        new OA\Property(property: 'size_bytes', type: 'integer', nullable: true, description: 'Only present on the list endpoint.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
class MysqlDatabaseSchema {}
