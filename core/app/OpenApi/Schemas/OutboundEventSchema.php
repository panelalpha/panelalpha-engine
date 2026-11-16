<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OutboundEvent',
    properties: [
        new OA\Property(property: 'owner', type: 'string', example: 'shop', description: 'The project, or for a host build the project whose checkout it built (the build container\'s own name when it mounted none).'),
        new OA\Property(property: 'network', type: 'string', enum: ['tenants', 'build'], example: 'tenants', description: '`tenants` for the project\'s running app, `build` for a host build of it.'),
        new OA\Property(property: 'kind', type: 'string', enum: ['scan', 'flood', 'smtp'], example: 'scan', description: '`scan`: more new addresses than the limit; `flood`: more new connections a second than the limit; `smtp`: mail sent straight to another server on port 25.'),
        new OA\Property(property: 'address', type: 'string', example: '10.200.128.5', description: 'The address the project or build held on the server\'s network.'),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'last_seen_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'blocked_until', type: 'string', format: 'date-time', nullable: true, description: 'A scan block\'s end; null for the events that only dropped the connections over a limit.'),
        new OA\Property(property: 'active', type: 'boolean', description: 'Whether it is in force now.'),
        new OA\Property(property: 'lifted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'lifted_by', type: 'string', nullable: true, enum: ['pae', 'api', null], description: 'Who lifted the block before it ended.'),
    ],
    type: 'object',
)]
class OutboundEventSchema {}
