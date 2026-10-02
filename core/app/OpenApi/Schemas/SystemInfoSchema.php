<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SystemInfo',
    properties: [
        new OA\Property(property: 'version', type: 'string', example: '1.5.0', nullable: true),
        new OA\Property(property: 'url', type: 'string', example: 'https://203-0-113-7.panelalpha.direct:2011', nullable: true, description: "The engine's base URL (APP_URL), on the name its TLS certificate is issued for"),
        new OA\Property(property: 'api_url', type: 'string', example: 'https://203-0-113-7.panelalpha.direct:2011/api', nullable: true),
        new OA\Property(property: 'mcp_url', type: 'string', example: 'https://203-0-113-7.panelalpha.direct:2011/mcp', nullable: true),
        new OA\Property(property: 'cert_domain', type: 'string', example: '203-0-113-7.panelalpha.direct', nullable: true, description: 'The name the served Let\'s Encrypt certificate was issued for (setting cert_domain); null while self-signed'),
        new OA\Property(property: 'default_ipv4', type: 'string', example: '203.0.113.7', nullable: true),
        new OA\Property(property: 'webserver', type: 'string', example: 'nginx', nullable: true),
        new OA\Property(property: 'php_version', type: 'string', example: '8.1', nullable: true),
        new OA\Property(property: 'os', type: 'string', example: 'Ubuntu 22.04', nullable: true),
        new OA\Property(property: 'uptime', type: 'string', nullable: true),
        new OA\Property(
            property: 'unreadable_secrets',
            description: 'Projects holding stored secrets the current APP_KEY cannot decrypt; each project\'s own '
                . '`unreadable_secrets` names them.',
            properties: [
                new OA\Property(property: 'count', type: 'integer', example: 0),
                new OA\Property(property: 'projects', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(
                    property: 'warning',
                    type: 'string',
                    nullable: true,
                    description: 'Says the APP_KEY may be invalid and what to do; null when every project decodes.'
                ),
            ],
            type: 'object'
        ),
    ],
    type: 'object',
)]
class SystemInfoSchema
{
}
