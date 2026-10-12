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
        new OA\Property(
            property: 'orphan_account_containers',
            description: 'Host containers started for a project account that no project owns any more, left by a '
                . 'create or delete that did not finish. The engine never removes them; each names the command '
                . 'that does.',
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
    ],
    type: 'object',
)]
class SystemInfoSchema
{
}
