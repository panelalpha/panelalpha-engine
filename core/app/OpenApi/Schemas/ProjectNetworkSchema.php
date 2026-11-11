<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProjectNetwork',
    description: 'Dedicated addresses; refused when the engine has no free one (`no_free_address`).',
    properties: [
        new OA\Property(property: 'dedicated_ipv4', type: 'boolean', example: false),
        new OA\Property(property: 'dedicated_ipv6', type: 'boolean', example: false),
    ],
    type: 'object',
)]
class ProjectNetworkSchema {}
