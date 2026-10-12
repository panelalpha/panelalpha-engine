<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'IpSubnet',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'ip', type: 'string', example: '192.168.1.0'),
        new OA\Property(property: 'mask', type: 'string', example: '255.255.255.0', nullable: true),
        new OA\Property(property: 'is_shared', type: 'boolean', example: false),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(
            property: 'assigned_ips',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/AssignedIp'),
            description: 'The addresses assigned out of this subnet.',
        ),
    ],
    type: 'object',
)]
class IpSubnetSchema
{
}
