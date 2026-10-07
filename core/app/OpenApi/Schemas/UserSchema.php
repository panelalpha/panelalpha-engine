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
        new OA\Property(
            property: 'protected',
            description: 'While true, an API or MCP token may not delete, stop or redeploy this project; only the '
                . 'operator\'s own commands can. The setting itself is read-only over the API, and is set with '
                . '`pae project:settings:set protected on --project=<username>`.',
            type: 'boolean',
            example: false,
        ),
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
            property: 'disk',
            description: 'Disk the project uses, measured every 15 minutes in the background; null before the first '
                . 'measurement. files_bytes is its home without ~/docker; docker_bytes is its own Docker\'s images, '
                . 'build cache, containers and volumes (null when it has none). limit_bytes is the project '
                . 'disk_space_limit, null when unlimited.',
            properties: [
                new OA\Property(property: 'files_bytes', type: 'integer', nullable: true, example: 23363584),
                new OA\Property(property: 'docker_bytes', type: 'integer', nullable: true, example: 1848709120),
                new OA\Property(property: 'total_bytes', type: 'integer', nullable: true, example: 1872072704),
                new OA\Property(property: 'limit_bytes', type: 'integer', nullable: true, example: 10737418240),
                new OA\Property(property: 'measured_at', type: 'string', example: '2026-10-07T09:15:02Z'),
            ],
            type: 'object',
            nullable: true,
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
