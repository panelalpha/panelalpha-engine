<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MysqlUser',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'user_id', type: 'integer', example: 1),
        new OA\Property(property: 'user', type: 'string', example: 'johndoe_user'),
        new OA\Property(property: 'details', type: 'object', nullable: true),
        new OA\Property(
            property: 'databases',
            type: 'array',
            items: new OA\Items(type: 'string'),
            example: ['johndoe_wp'],
            description: 'Only present on the list endpoint.',
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
class MysqlUserSchema
{
}
