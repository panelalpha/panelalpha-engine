<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'VaultConfig',
    properties: [
        new OA\Property(
            property: 'globals',
            type: 'array',
            items: new OA\Items(properties: [
                new OA\Property(property: 'ref', type: 'string', example: 'vault:7'),
                new OA\Property(property: 'type', type: 'string', example: 'git_token'),
                new OA\Property(property: 'purpose', type: 'string', nullable: true, example: 'GitHub org read token'),
            ], type: 'object'),
            description: 'Global entries with a secret pasted: usable by any project that passes the `ref`.',
        ),
    ],
    type: 'object',)]
class VaultConfigSchema
{
}
