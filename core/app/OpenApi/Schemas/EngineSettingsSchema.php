<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SettingSource',
    type: 'string',
    enum: ['setting', 'env', 'default'],
    description: '`setting` when the engine database stores the value, `env` when .env-core sets it, `default` when neither does.',
)]
#[OA\Schema(
    schema: 'TelemetrySettings',
    required: ['sending', 'tier', 'source_bundle', 'bug_reports', 'queued', 'endpoint'],
    properties: [
        new OA\Property(property: 'sending', type: 'object', properties: [
            new OA\Property(property: 'value', type: 'boolean', example: true),
            new OA\Property(property: 'source', ref: '#/components/schemas/SettingSource'),
        ]),
        new OA\Property(property: 'tier', type: 'object', properties: [
            new OA\Property(property: 'value', type: 'string', enum: ['metadata', 'repo', 'log'], example: 'log'),
            new OA\Property(property: 'source', ref: '#/components/schemas/SettingSource'),
        ]),
        new OA\Property(property: 'source_bundle', type: 'object', properties: [
            new OA\Property(property: 'value', type: 'string', enum: ['off', 'unexplained', 'failed'], example: 'off'),
            new OA\Property(property: 'source', ref: '#/components/schemas/SettingSource'),
        ]),
        new OA\Property(property: 'bug_reports', type: 'object', properties: [
            new OA\Property(property: 'value', type: 'boolean', example: true),
            new OA\Property(property: 'source', ref: '#/components/schemas/SettingSource'),
        ]),
        new OA\Property(property: 'queued', type: 'integer', description: 'Reports waiting to be sent.', example: 0),
        new OA\Property(property: 'endpoint', type: 'string', nullable: true, description: 'Where reports go; null when nowhere is configured and they are held.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PrewarmSettings',
    required: ['images', 'budget', 'reserve', 'available'],
    properties: [
        new OA\Property(property: 'images', type: 'object', properties: [
            new OA\Property(property: 'value', type: 'array', items: new OA\Items(type: 'string'), example: ['php:8.3', 'composer']),
            new OA\Property(property: 'source', ref: '#/components/schemas/SettingSource'),
            new OA\Property(property: 'unknown', type: 'array', items: new OA\Items(type: 'string'), description: 'Selected ids the catalogue no longer has; kept, and warmed again if a release restores them.'),
        ]),
        new OA\Property(property: 'budget', type: 'object', properties: [
            new OA\Property(property: 'value', type: 'string', example: '6G', description: 'The most disk one prewarm may spend, or none.'),
            new OA\Property(property: 'source', ref: '#/components/schemas/SettingSource'),
            new OA\Property(property: 'default', type: 'string', example: '6G'),
        ]),
        new OA\Property(property: 'reserve', type: 'object', properties: [
            new OA\Property(property: 'value', type: 'string', example: '10G', description: 'Free disk a prewarm never takes the host below.'),
            new OA\Property(property: 'source', ref: '#/components/schemas/SettingSource'),
            new OA\Property(property: 'default', type: 'string', example: '10G'),
        ]),
        new OA\Property(property: 'available', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'id', type: 'string', example: 'php:8.3'),
            new OA\Property(property: 'kind', type: 'string', enum: ['build', 'pull']),
            new OA\Property(property: 'runtime', type: 'string', example: 'php'),
            new OA\Property(property: 'why', type: 'string'),
        ], type: 'object')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SitesDbCache',
    required: ['value', 'source', 'default', 'running_mb'],
    properties: [
        new OA\Property(property: 'value', type: 'string', example: '32M'),
        new OA\Property(property: 'source', ref: '#/components/schemas/SettingSource'),
        new OA\Property(property: 'default', type: 'string', example: '32M'),
        new OA\Property(property: 'running_mb', type: 'integer', nullable: true, description: 'What the running mariadbd uses; null while sites-db is not running.', example: 32),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SitesDbCaches',
    required: ['innodb_buffer_pool_size', 'key_buffer_size', 'aria_pagecache_buffer_size'],
    properties: [
        new OA\Property(property: 'innodb_buffer_pool_size', ref: '#/components/schemas/SitesDbCache'),
        new OA\Property(property: 'key_buffer_size', ref: '#/components/schemas/SitesDbCache'),
        new OA\Property(property: 'aria_pagecache_buffer_size', ref: '#/components/schemas/SitesDbCache'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'EngineAddress',
    required: ['url', 'cert_domain', 'cert_email', 'default_tunnel', 'served_certificate'],
    properties: [
        new OA\Property(property: 'url', type: 'object', properties: [
            new OA\Property(property: 'value', type: 'string', nullable: true, example: 'https://panel.example.com:2011'),
            new OA\Property(property: 'source', ref: '#/components/schemas/SettingSource'),
        ]),
        new OA\Property(property: 'cert_domain', type: 'object', properties: [
            new OA\Property(property: 'value', type: 'string', nullable: true, example: 'panel.example.com', description: 'Null for the .panelalpha.direct default.'),
            new OA\Property(property: 'source', ref: '#/components/schemas/SettingSource'),
        ]),
        new OA\Property(property: 'cert_email', type: 'object', properties: [
            new OA\Property(property: 'value', type: 'string', nullable: true, example: 'ops@example.com'),
            new OA\Property(property: 'source', ref: '#/components/schemas/SettingSource'),
        ]),
        new OA\Property(property: 'default_tunnel', type: 'object', properties: [
            new OA\Property(property: 'value', type: 'string', enum: ['panelalpha', 'none'], example: 'panelalpha'),
            new OA\Property(property: 'source', ref: '#/components/schemas/SettingSource'),
        ]),
        new OA\Property(property: 'served_certificate', type: 'object', nullable: true, description: 'The certificate answering on `url`, read off the wire; null when nothing answered.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'McpScope',
    required: ['permission_mode', 'commands', 'commands_source', 'offered', 'total', 'toolsets', 'denied_regex', 'env'],
    properties: [
        new OA\Property(property: 'permission_mode', type: 'object', properties: [
            new OA\Property(property: 'value', type: 'string', enum: ['readonly', 'modify', 'full'], example: 'full'),
            new OA\Property(property: 'source', ref: '#/components/schemas/SettingSource'),
        ]),
        new OA\Property(property: 'commands', type: 'array', items: new OA\Items(type: 'string'), description: 'The commands selected, whatever the ceiling then does with them.'),
        new OA\Property(property: 'commands_source', ref: '#/components/schemas/SettingSource'),
        new OA\Property(property: 'offered', type: 'array', items: new OA\Items(type: 'string'), description: 'The commands an assistant is offered.'),
        new OA\Property(property: 'total', type: 'integer', description: 'Every command this engine has.', example: 311),
        new OA\Property(property: 'toolsets', type: 'object', additionalProperties: new OA\AdditionalProperties(properties: [
            new OA\Property(property: 'total', type: 'integer'),
            new OA\Property(property: 'exposed', type: 'integer'),
        ], type: 'object')),
        new OA\Property(property: 'denied_regex', type: 'string', nullable: true, description: 'MCP_DENIED_TOOLS_REGEX, kept as it is.'),
        new OA\Property(property: 'env', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string'), description: 'The scope in force, written as MCP_* lines.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'OperatorApiToken',
    required: ['id', 'name', 'routes', 'projects', 'legacy', 'expires_at', 'last_used_at', 'revoked_at', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 12),
        new OA\Property(property: 'name', type: 'string', example: 'deploy-bot'),
        new OA\Property(property: 'routes', type: 'array', nullable: true, items: new OA\Items(type: 'string'), description: 'The operations it may call, "VERB /path"; null for every one.'),
        new OA\Property(property: 'projects', type: 'array', nullable: true, items: new OA\Items(type: 'string'), description: 'The projects it may reach; null for every one.'),
        new OA\Property(property: 'legacy', type: 'boolean', description: 'A token from before tokens had kinds; it reaches the API and MCP alike.'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'last_used_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'revoked_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'plain_text_token', type: 'string', description: 'Only in the answer to POST /api-tokens.'),
    ],
    type: 'object',
)]
class EngineSettingsSchema {}
