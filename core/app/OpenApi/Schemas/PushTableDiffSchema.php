<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PushTableDiff',
    properties: [
        new OA\Property(property: 'database', type: 'string', example: 'johndoe_wp'),
        new OA\Property(property: 'name', type: 'string', example: 'wp_posts'),
    ],
    type: 'object',
)]
class PushTableDiffSchema {}
