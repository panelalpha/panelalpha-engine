<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ApiAccess',
    properties: [
        new OA\Property(property: 'project', type: 'string', example: 'panel'),
        new OA\Property(
            property: 'scope',
            description: 'What the token may act on: `self` (only this project), `all` (every project on the engine), '
                .'or a list of project usernames.',
            oneOf: [
                new OA\Schema(type: 'string', enum: ['self', 'all']),
                new OA\Schema(type: 'array', items: new OA\Items(type: 'string')),
            ],
            example: 'self',
        ),
        new OA\Property(property: 'mcp', type: 'boolean', example: false, description: 'Whether the token may also call MCP tools.'),
        new OA\Property(property: 'token_id', type: 'integer', example: 12, description: 'The minted token\'s id; its value is never returned.'),
        new OA\Property(property: 'allowed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(
            property: 'pending_revoke_token_id',
            type: 'integer',
            example: null,
            nullable: true,
            description: 'A token an earlier replacement left valid, because the apply that would have revoked it '
                .'has not succeeded. It is revoked by the next successful apply or rotation of that project.'
        ),
        new OA\Property(
            property: 'warning',
            type: 'string',
            nullable: true,
            description: 'Set while a previous token is still valid, and says whether the apply that replaces it '
                .'failed or has simply not finished; null otherwise.'
        ),
    ],
    type: 'object',
)]
class ApiAccessSchema {}
