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
        new OA\Property(
            property: 'public_routing',
            description: 'What the project\'s panelalpha.yaml asks for beyond its domain (`domain: host`, `public_ports:`), what the '
                .'operator allows it (`pae system:public-routing:allow`), and what its last deploy applied. Null when its last deploy '
                .'asked for nothing.',
            properties: [
                new OA\Property(property: 'allowed', type: 'array', items: new OA\Items(type: 'string', enum: ['ports', 'host_domain'])),
                new OA\Property(property: 'asked', properties: [
                    new OA\Property(property: 'domain', type: 'string', enum: ['generated', 'host']),
                    new OA\Property(property: 'ports', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'listen', type: 'integer', example: 8081),
                        new OA\Property(property: 'to', type: 'integer', example: 3000),
                        new OA\Property(property: 'transport', type: 'string', enum: ['tcp', 'udp']),
                    ], type: 'object')),
                ], type: 'object'),
                new OA\Property(property: 'domain', properties: [
                    new OA\Property(property: 'state', type: 'string', enum: ['host', 'generated', 'refused', 'not_allowed']),
                    new OA\Property(property: 'name', type: 'string', nullable: true),
                    new OA\Property(property: 'reason', type: 'string'),
                ], type: 'object', nullable: true),
                new OA\Property(property: 'ports', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'listen', type: 'integer', example: 8081),
                    new OA\Property(property: 'to', type: 'integer', example: 3000),
                    new OA\Property(property: 'transport', type: 'string', enum: ['tcp', 'udp']),
                    new OA\Property(property: 'state', type: 'string', enum: ['open', 'refused', 'not_allowed']),
                    new OA\Property(property: 'reason', type: 'string'),
                    new OA\Property(property: 'rule_id', type: 'integer', description: 'The proxy rule, while open'),
                ], type: 'object')),
            ],
            type: 'object',
            nullable: true,
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
                .'bandwidth_limit in bytes, -1 when unlimited and null when no limit is set. The limit is only '
                .'reported: a project past it keeps serving.',
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
                new OA\Property(property: 'type', type: 'string', nullable: true, example: 'wordpress', enum: ['wordpress', 'laravel', 'php', 'node', 'python', 'ruby', 'go', 'rust', 'java', 'dotnet', 'docker', 'static', 'other'], description: 'A container project: what its last deploy built, null before the first deploy. A PHP hosting project: wordpress when WordPress core is in its main domain\'s document root or in the directory the engine installed it into, even if the site is broken or not set up, otherwise php; refreshed every minute and after a WordPress core download or install, null until first read.'),
                new OA\Property(property: 'path', type: 'string', nullable: true, example: '/home/alice/example.test/public_html/blog', description: 'A PHP hosting project reported as wordpress: the absolute directory the engine installed WordPress into, when that is not the main domain\'s document root; null for a WordPress in the document root, for any other type and for a project in its own containers. The WP-CLI operations use it when no path is given.'),
                new OA\Property(property: 'framework', type: 'string', nullable: true, example: 'nextjs', description: 'The deploy strategy that built it'),
                new OA\Property(property: 'label', type: 'string', nullable: true, example: 'WordPress', description: 'Human name of what was detected'),
                new OA\Property(property: 'status', type: 'string', example: 'live', enum: ['live', 'building', 'failed', 'down', 'suspended', 'none'], description: 'building: a deploy is queued or running. failed: the last deploy failed. down: deployed, but the account container is not running. none: never deployed'),
                new OA\Property(property: 'public_url', type: 'string', nullable: true, example: 'https://blog-x7k2.panelalpha.online', description: 'A tunnel hostname when the project has one, otherwise its domain'),
                new OA\Property(property: 'admin_sso', type: 'boolean', example: true, description: 'Whether POST /projects/{username}/app/users/{userId}/sso can log a user in'),
            ],
            type: 'object',
        ),
        new OA\Property(
            property: 'source',
            description: 'Where the project\'s code comes from, in the words of the `source` POST /projects takes: what a rebuild deploys. '
                .'Never a token or key.',
            properties: [
                new OA\Property(property: 'type', type: 'string', enum: ['none', 'git', 'archive', 'project', 'wordpress'], example: 'git'),
                new OA\Property(property: 'url', type: 'string', nullable: true, example: 'git@github.com:acme/shop.git', description: 'git: the repository, credentials removed. archive: the URL it was downloaded from'),
                new OA\Property(property: 'branch', type: 'string', nullable: true, example: 'main', description: 'git only'),
                new OA\Property(property: 'auth', type: 'string', nullable: true, enum: ['none', 'token', 'deploy_key'], description: 'git only: how a clone authenticates'),
                new OA\Property(property: 'rebuild', type: 'string', nullable: true, enum: ['clone', 'checkout'], description: 'git only. clone: a rebuild clones the repository again. checkout: it builds ~/project as it is (connected with git_connect); git pull brings new commits'),
                new OA\Property(property: 'upload_id', type: 'string', nullable: true, description: 'archive: the upload it was deployed from'),
                new OA\Property(property: 'path', type: 'string', nullable: true, description: 'archive: the file in the home it was deployed from'),
                new OA\Property(property: 'from', type: 'string', nullable: true, example: 'shop', description: 'project: the project it was copied from'),
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
