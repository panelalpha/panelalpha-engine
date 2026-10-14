<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SourceRefs',
    properties: [
        new OA\Property(property: 'repository', type: 'string', example: 'https://github.com/owner/repo.git'),
        new OA\Property(property: 'default_branch', type: 'string', nullable: true, example: 'main'),
        new OA\Property(
            property: 'branches',
            type: 'array',
            items: new OA\Items(type: 'string'),
            example: ['main', 'develop'],
        ),
        new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string'), example: ['v1.2.0']),
        new OA\Property(property: 'truncated', type: 'boolean', example: false),
    ],
    type: 'object',
)]
class SourceRefsSchema
{
}
