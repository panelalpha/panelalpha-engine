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
                .'operator\'s own commands can. The setting itself is read-only over the API, and is set with '
                .'`pae project:settings:set protected on --project=<username>`.',
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
                .'measurement. files_bytes is its home without ~/docker; docker_bytes is its own Docker\'s images, '
                .'build cache, containers and volumes (null when it has none). limit_bytes is the project '
                .'disk_space_limit in bytes; -1 when unlimited, null when no limit is set.',
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
            property: 'bandwidth',
            description: 'Transfer this calendar month, measured hourly in the background; null before the first '
                .'measurement. month is the month it counts, used_bytes the same figure GET '
                .'/projects/{username}/bandwidth?group_by=month gives for it. limit_bytes is the project '
                .'bandwidth_limit in bytes, -1 when unlimited and null when no limit is set.',
            properties: [
                new OA\Property(property: 'month', type: 'string', example: '2026-10'),
                new OA\Property(property: 'used_bytes', type: 'integer', example: 734003200),
                new OA\Property(property: 'limit_bytes', type: 'integer', nullable: true, example: 107374182400),
                new OA\Property(property: 'measured_at', type: 'string', example: '2026-10-14T09:00:01Z'),
            ],
            type: 'object',
            nullable: true,
        ),
        new OA\Property(
            property: 'unreadable_secrets',
            type: 'array',
            items: new OA\Items(type: 'string'),
            description: 'Stored secrets that cannot be decoded, e.g. `git_token`, `env_vars`, '
                .'`site_git.public_html.token`. They read as empty and the next save stores them empty. Empty when every secret reads.',
            example: []
        ),
        new OA\Property(property: 'warning', type: 'string', nullable: true, description: 'Names the secrets that cannot be decoded and what to do; null when there are none.'),
        new OA\Property(
            property: 'app',
            description: 'The deployed application, normalized. Read from the database, the deploy lock and the running containers; nothing inside the account.',
            properties: [
                new OA\Property(property: 'type', type: 'string', nullable: true, example: 'wordpress', enum: ['wordpress', 'laravel', 'php', 'node', 'python', 'ruby', 'go', 'rust', 'java', 'dotnet', 'docker', 'static', 'other'], description: 'null before the first deploy'),
                new OA\Property(property: 'framework', type: 'string', nullable: true, example: 'nextjs', description: 'The deploy strategy that built it'),
                new OA\Property(property: 'label', type: 'string', nullable: true, example: 'WordPress', description: 'Human name of what was detected'),
                new OA\Property(property: 'status', type: 'string', example: 'live', enum: ['live', 'building', 'failed', 'down', 'suspended', 'none'], description: 'building: a deploy is queued or running. failed: the last deploy failed. down: deployed, but the account container is not running. none: never deployed'),
                new OA\Property(property: 'public_url', type: 'string', nullable: true, example: 'https://blog-x7k2.panelalpha.online', description: 'A tunnel hostname when the project has one, otherwise its domain'),
                new OA\Property(property: 'admin_sso', type: 'boolean', example: true, description: 'Whether POST /projects/{username}/app/users/{userId}/sso can log a user in'),
            ],
            type: 'object',
        ),
        new OA\Property(
            property: 'usage',
            description: 'CPU and memory of the project\'s DinD container (its applications and its own Docker daemon) from the sample taken every minute; null when there is no sample from the last five minutes, e.g. the project is stopped.',
            properties: [
                new OA\Property(property: 'cpu_percent', type: 'number', format: 'float', nullable: true, example: 3.2, description: 'One core is 100; null on the first sample after a start'),
                new OA\Property(property: 'memory_bytes', type: 'integer', nullable: true, example: 76701696, description: 'Without page cache the kernel can drop at once, as docker stats counts it'),
                new OA\Property(property: 'memory_limit_bytes', type: 'integer', nullable: true, example: 1073741824),
                new OA\Property(property: 'sampled_at', type: 'string', format: 'date-time'),
            ],
            type: 'object',
            nullable: true,
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
class UserSchema {}
