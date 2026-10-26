<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CdnStatus',
    properties: [
        new OA\Property(property: 'provider', type: 'string', enum: ['cloudflare'], example: 'cloudflare'),
        new OA\Property(property: 'zone_id', type: 'string', example: '023e105f4ecef8ad9ca31a8372d0c353'),
        new OA\Property(property: 'zone_name', type: 'string', example: 'example.com'),
        new OA\Property(property: 'proxied', type: 'boolean', description: "Whether the domain's DNS record is proxied through Cloudflare.", example: true),
        new OA\Property(property: 'security_level', type: 'string', enum: ['off', 'essentially_off', 'low', 'medium', 'high', 'under_attack'], example: 'medium'),
        new OA\Property(property: 'development_mode', type: 'boolean', example: false),
    ],
    type: 'object',
)]
class CdnStatusSchema {}
