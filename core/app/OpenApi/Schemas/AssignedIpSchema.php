<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AssignedIp',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'user_id', type: 'integer', example: 1),
        new OA\Property(property: 'username', type: 'string', example: 'johndoe'),
        new OA\Property(
            property: 'domain_names',
            type: 'array',
            items: new OA\Items(type: 'string'),
            example: ['johndoe.example.com'],
        ),
        new OA\Property(property: 'ip_subnet_id', type: 'integer', example: 1),
        new OA\Property(property: 'ip_address', type: 'string', example: '192.168.1.10'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(
            property: 'reload_pending',
            type: 'boolean',
            description: 'Only present on assign/unassign: true when the network reload still has to run.',
        ),
    ],
    type: 'object',
)]
class AssignedIpSchema
{
}
