<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'VaultConfig',
    properties: [
        new OA\Property(
            property: 'project_scoped_tokens',
            type: 'boolean',
            description: 'True keeps every project on the credentials it was given — nothing falls back to the '
                . "engine's global vault entries. False (the default) shares them, so a Git or Cloudflare token "
                . 'is pasted once rather than at every project.',
            example: false,
        ),
        new OA\Property(property: 'global_secrets_shared', type: 'boolean', description: 'The same answer the other way round, for readability.', example: true),
        new OA\Property(
            property: 'global_types',
            type: 'array',
            items: new OA\Items(type: 'string'),
            description: 'The types that actually have an engine-wide secret pasted.',
            example: ['cloudflare_api_token', 'git_token'],
        ),
    ],
    type: 'object',)]
class VaultConfigSchema
{
}
