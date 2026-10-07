<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'User',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'username', type: 'string', example: 'johndoe'),
        new OA\Property(property: 'domain', type: 'string', example: 'johndoe.example.com'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com', nullable: true),
        new OA\Property(property: 'status', type: 'string', example: 'active', enum: ['active', 'suspended']),
        new OA\Property(property: 'config', type: 'object', nullable: true),
        new OA\Property(property: 'details', type: 'object', nullable: true),
        new OA\Property(
            property: 'app_credentials',
            description: 'Whether the engine generated a login for the deployed application, and where to read it. Never a value: GET `endpoint` returns them.',
            properties: [
                new OA\Property(property: 'available', type: 'boolean'),
                new OA\Property(property: 'fields', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'kind', type: 'string', enum: ['username', 'email', 'password']),
                ], type: 'object')),
                new OA\Property(property: 'login_url', type: 'string', nullable: true),
                new OA\Property(property: 'endpoint', type: 'string', example: '/api/projects/johndoe/app-credentials'),
            ],
            type: 'object',
        ),
        new OA\Property(
            property: 'unreadable_secrets',
            type: 'array',
            items: new OA\Items(type: 'string'),
            description: 'Stored secrets that cannot be decoded, e.g. `git_token`, `env_vars`, '
                . '`site_git.public_html.token`. They read as empty and the next save stores them empty. Empty when every secret reads.',
            example: []
        ),
        new OA\Property(property: 'warning', type: 'string', nullable: true, description: 'Names the secrets that cannot be decoded and what to do; null when there are none.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
class UserSchema
{
}
