<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SystemInfo',
    properties: [
        new OA\Property(property: 'version', type: 'string', example: '1.5.0', nullable: true),
        new OA\Property(
            property: 'features',
            type: 'array',
            items: new OA\Items(type: 'string'),
            example: ['project_source'],
            description: 'What this engine\'s API offers that an older engine\'s does not; a client talking to engines of '
                .'several versions checks this rather than the version. `project_source`: POST /projects takes the '
                .'typed body with `source` (none, git, archive, project, wordpress), PUT /projects/{username}/source, '
                .'POST /uploads and POST /vault/deploy-keys exist, and GET /projects/{username} returns `source`.',
        ),
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
        new OA\Property(property: 'sites_base_domain', type: 'string', example: 'example.com', nullable: true, description: 'The base domain the operator configured: <name>.<sites_base_domain>. Null while unset; project_domain_bases says where projects land then.'),
        new OA\Property(
            property: 'project_domain_bases',
            description: 'The domains a project created with neither `domain` nor `tunnel` is named under, best '
                .'first: the first is the default, and each next one is used when the one before cannot be had. '
                .'A suffixed base names the project `<name>-<4 hex>` instead of `<name>`, and that label is bought '
                .'from PanelAlpha Online at create time, which can be refused (panelalpha_online.last_error). '
                .'`local` resolves nowhere.',
            type: 'array',
            items: new OA\Items(properties: [
                new OA\Property(property: 'base', type: 'string', example: 'panelalpha.online'),
                new OA\Property(
                    property: 'source',
                    type: 'string',
                    enum: ['sites_base_domain', 'panelalpha_online', 'panelalpha_direct', 'local'],
                    example: 'panelalpha_online',
                    description: 'The details.domain.source a project named there reports.'
                ),
                new OA\Property(property: 'suffixed', type: 'boolean', example: true),
                new OA\Property(
                    property: 'publicly_resolvable',
                    type: 'boolean',
                    example: true,
                    nullable: true,
                    description: "Whether a name there reaches this host from the internet; null where the DNS is not the engine's."
                ),
            ], type: 'object'),
        ),
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
        new OA\Property(
            property: 'default_backup_container',
            nullable: true,
            description: 'Where a backup goes when the caller names no store: the store this host set up, or the one an '
                .'operator made the default. Null when this host has no store. The same store answers `default: true` in '
                .'GET /backup-containers.',
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'name', type: 'string', example: 'local'),
                new OA\Property(property: 'driver', type: 'string', example: 'local'),
                new OA\Property(property: 'location', type: 'string', example: '/opt/panelalpha/backups/local'),
            ],
            type: 'object',
        ),
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
                .'`unreadable_secrets` names them. A token scoped to projects sees only the projects in its scope.',
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
                .'that does. A token scoped to projects sees only those of the projects in its scope.',
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
            property: 'tenant_network_firewall',
            description: 'The tenant network firewall, which the root broker applies whenever Docker starts an account '
                .'container on its own (docker start, a daemon restart, a reboot); until it does, such a container has no '
                .'network. Null for a token scoped to projects.',
            nullable: true,
            allOf: [new OA\Schema(ref: '#/components/schemas/HostScriptReport')],
        ),
        new OA\Property(
            property: 'host_firewall',
            description: 'The host firewall\'s Docker hook (scripts/firewall.sh --apply), which the root broker puts back each '
                .'time it starts; without it the ports Docker publishes may not be filtered as the firewall\'s rules say. '
                .'Null for a token scoped to projects.',
            nullable: true,
            allOf: [new OA\Schema(ref: '#/components/schemas/HostScriptReport')],
        ),
        new OA\Property(
            property: 'host',
            description: 'The machine the engine runs on. Each field is null when it cannot be read. Null for a token scoped to projects.',
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
            nullable: true,
        ),
    ],
    type: 'object',
)]
class SystemInfoSchema {}
