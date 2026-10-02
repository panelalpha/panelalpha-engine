<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TrustedAddress',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '9f3a1c0d2e4b', description: 'Derived from the address.'),
        new OA\Property(property: 'address', type: 'string', example: '203.0.113.7'),
        new OA\Property(property: 'comment', type: 'string', nullable: true, example: 'office'),
    ],
    type: 'object',
)]
class TrustedAddressSchema
{
}
