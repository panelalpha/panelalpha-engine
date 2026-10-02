<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'FirewallRule',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '3f2a9c41d0be', description: 'Derived from what the rule matches; stable while other rules change.'),
        new OA\Property(property: 'action', type: 'string', enum: ['allow', 'deny'], example: 'allow', description: 'A rule written on the host may also say reject or limit.'),
        new OA\Property(property: 'direction', type: 'string', enum: ['in', 'out', 'both'], example: 'in', description: 'both: traffic from source coming in and to it going out, one rule.'),
        new OA\Property(property: 'protocol', type: 'string', enum: ['tcp', 'udp'], nullable: true, description: 'null is any.'),
        new OA\Property(property: 'port', type: 'string', nullable: true, example: '22', description: 'Destination port, range (30000:30009) or list; null is any.'),
        new OA\Property(property: 'source', type: 'string', nullable: true, example: '203.0.113.7', description: 'Address or CIDR; null is any.'),
        new OA\Property(property: 'destination', type: 'string', nullable: true, description: 'Address or CIDR; null is any.'),
        new OA\Property(property: 'comment', type: 'string', nullable: true),
        new OA\Property(property: 'managed', type: 'boolean', description: 'Opened by the engine; the API does not change or remove it.'),
        new OA\Property(property: 'editable', type: 'boolean', description: 'false for engine rules and for rules written on the host with options the API does not carry.'),
        new OA\Property(property: 'raw', type: 'string', nullable: true, description: 'The provider\'s own form of a rule that is not editable.'),
    ],
    type: 'object',
)]
class FirewallRuleSchema
{
}
