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
        new OA\Property(
            property: 'default_tunnel',
            type: 'string',
            enum: ['panelalpha', 'none'],
            example: 'panelalpha',
            nullable: true,
            description: 'What a project created without `tunnel` is published under (setting default_tunnel). '
                .'`none` skips PanelAlpha Online and keeps the name the domain already resolves on '
                .'(`<name>.<cert_domain>` or the configured sites_base_domain). `panelalpha` and null both '
                .'mean the default ladder: a configured sites_base_domain first, a free panelalpha.online '
                .'label otherwise.',
        ),
        new OA\Property(
            property: 'served_certificate',
            type: 'object',
            nullable: true,
            description: 'What :2011 is actually presenting, which can differ from cert_domain.',
        ),
        new OA\Property(property: 'sites_base_domain', type: 'string', example: 'example.com', nullable: true, description: 'Where a project without a domain of its own lands: <name>.<sites_base_domain>.'),
        new OA\Property(
            property: 'sites_certificates',
            properties: [
                new OA\Property(property: 'issuer', type: 'string', example: 'letsencrypt'),
                new OA\Property(property: 'shared_zone', type: 'boolean', example: false),
                new OA\Property(property: 'shared_zone_issuance', type: 'boolean', example: false),
            ],
            type: 'object',
        ),
        new OA\Property(property: 'default_ipv4', type: 'string', example: '203.0.113.7', nullable: true),
        new OA\Property(property: 'default_ipv6', type: 'string', example: '2001:db8::1', nullable: true),
        new OA\Property(property: 'ipv4_nat_mode', type: 'boolean', example: false),
        new OA\Property(property: 'ipv4_nat_maps', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'webserver', type: 'object', description: 'The active webserver\'s details, as getDetails() returns them.'),
        new OA\Property(property: 'latest_webserver_change', type: 'object', nullable: true),
        new OA\Property(
            property: 'latest_update',
            nullable: true,
            description: 'The most recent engine update; null when there has never been one.',
            allOf: [new OA\Schema(ref: '#/components/schemas/EngineUpdateRun')],
        ),
        new OA\Property(
            property: 'panelalpha_online',
            properties: [
                new OA\Property(property: 'last_error', type: 'string', nullable: true, description: 'Why new projects stopped getting panelalpha.online names; null once labels are sold again.'),
            ],
            type: 'object',
        ),
        new OA\Property(
            property: 'unreadable_secrets',
            description: 'Projects holding stored secrets the current APP_KEY cannot decrypt; each project\'s own '
                .'`unreadable_secrets` names them.',
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
        new OA\Property(
            property: 'orphan_account_containers',
            description: 'Host containers started for a project account that no project owns any more, left by a '
                .'create or delete that did not finish. The engine never removes them; each names the command '
                .'that does.',
            properties: [
                new OA\Property(property: 'count', type: 'integer', example: 0),
                new OA\Property(
                    property: 'containers',
                    type: 'array',
                    items: new OA\Items(properties: [
                        new OA\Property(property: 'name', type: 'string', example: 'shop'),
                        new OA\Property(property: 'state', type: 'string', example: 'exited'),
                        new OA\Property(property: 'project', type: 'string', example: 'shop'),
                        new OA\Property(property: 'cleanup', type: 'string', example: 'docker rm -f shop'),
                    ], type: 'object')
                ),
                new OA\Property(
                    property: 'warning',
                    type: 'string',
                    nullable: true,
                    description: 'Names the containers and the commands to run on the host; null when there are none.'
                ),
            ],
            type: 'object'
        ),
        new OA\Property(
            property: 'host',
            description: 'The machine the engine runs on. Each field is null when it cannot be read.',
            properties: [
                new OA\Property(property: 'hostname', type: 'string', example: 'srv1', nullable: true),
                new OA\Property(property: 'os', type: 'string', example: 'Ubuntu 24.04.3 LTS', nullable: true),
                new OA\Property(property: 'kernel', type: 'string', example: '6.8.0-85-generic', nullable: true),
                new OA\Property(property: 'uptime_seconds', type: 'integer', example: 864000, nullable: true),
                new OA\Property(property: 'cpu_cores', type: 'integer', example: 4, nullable: true),
                new OA\Property(property: 'memory_total_bytes', type: 'integer', example: 8321499136, nullable: true),
                new OA\Property(property: 'disk_total_bytes', type: 'integer', example: 160982630400, nullable: true, description: 'Size of the disk holding /home, where every project keeps its files and images'),
                new OA\Property(property: 'ipv4', type: 'string', example: '203.0.113.7', nullable: true, description: 'The main public IPv4 (same as default_ipv4)'),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
class SystemInfoSchema {}
