<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'PanelAlpha Engine API',
    description: <<<'MARKDOWN'
        REST API for managing projects, domains, databases, files, and server
        infrastructure in the PanelAlpha hosting platform.

        **A "project" is what earlier versions of this API called a "user":** one
        hosting account with its own container, domains, databases and files. The
        resource is documented here under `/projects`.

        Every `/projects/...` path below is also served at the identical
        `/users/...` path, with the same parameters, body and response. That alias
        is deprecated but permanent -- it is not scheduled for removal, so existing
        integrations do not need to change. New clients should use `/projects`.

        Note that `mysql/users` and `app/users` are *not* the renamed resource:
        they are MySQL accounts and accounts inside the deployed application
        respectively, and keep their names.
        MARKDOWN,
)]
#[OA\Server(url: '/api')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    description: 'Laravel Sanctum bearer token. Obtain a token via your authentication flow and include it as: Authorization: Bearer {token}',
)]
#[OA\Tag(name: 'Projects', description: 'Hosting project management (formerly "users")')]
#[OA\Tag(name: 'Domains', description: 'User domain management')]
#[OA\Tag(name: 'Domain PHP', description: 'PHP version per domain')]
#[OA\Tag(name: 'SSL Certificates', description: 'SSL certificate operations')]
#[OA\Tag(name: 'Domain Log Files', description: 'Access domain access/error logs')]
#[OA\Tag(name: 'MySQL Databases', description: 'MySQL database management')]
#[OA\Tag(name: 'MySQL Users', description: 'MySQL user management')]
#[OA\Tag(name: 'MySQL Privileges', description: 'MySQL privilege management')]
#[OA\Tag(name: 'MySQL Server', description: 'MySQL server info and phpMyAdmin SSO')]
#[OA\Tag(name: 'FTP Accounts', description: 'FTP account management')]
#[OA\Tag(name: 'SFTP Accounts', description: 'SFTP account management')]
#[OA\Tag(name: 'Cron Jobs', description: 'Cron job management')]
#[OA\Tag(name: 'Files', description: 'File system operations')]
#[OA\Tag(name: 'Git', description: 'Site-directory git operations')]
#[OA\Tag(name: 'PHP', description: 'PHP version and INI settings')]
#[OA\Tag(name: 'Containers', description: 'Docker container management per user')]
#[OA\Tag(name: 'App Users', description: 'WordPress/app user management and SSO')]
#[OA\Tag(name: 'Usage', description: 'Resource usage statistics')]
#[OA\Tag(name: 'WP-CLI', description: 'WP-CLI command execution')]
#[OA\Tag(name: 'Proxy Rules', description: 'Reverse proxy rule management')]
#[OA\Tag(name: 'System', description: 'System information and configuration')]
#[OA\Tag(name: 'Firewall', description: 'The host firewall (ufw)')]
#[OA\Tag(name: 'ModSecurity', description: 'ModSecurity WAF management')]
#[OA\Tag(name: 'Server Metrics', description: 'Real-time and historical server metrics')]
#[OA\Tag(name: 'IP Management', description: 'IP subnet and assignment management')]
#[OA\Tag(name: 'Lighthouse', description: 'Google Lighthouse performance reports')]
#[OA\Tag(name: 'Tasks', description: 'Queued job overlay: poll status and logs, cancel work')]
#[OA\Tag(name: 'Backup Containers', description: 'Backup storage container management')]
#[OA\Tag(name: 'Bug Reports', description: 'Report an engine bug to PanelAlpha over the telemetry channel')]
#[OA\Schema(
    schema: 'ErrorResponse',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Not Found'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ValidationErrorResponse',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'The given data was invalid.'),
        new OA\Property(property: 'errors', type: 'object', example: ['field' => ['Validation message']]),
        new OA\Property(
            property: 'problems',
            type: 'array',
            description: 'Present where the failure had more to say than a sentence. One entry per '
                . 'thing that is wrong, each with the `field` it belongs to, a stable `code` to '
                . 'branch on, and the same `message` that appears in `errors`. A failed deploy adds '
                . '`stage` and `deploy_log_offset`. Codes for a build failure are the deploy '
                . "explainer's rule slugs, the same identifiers deploy telemetry reports.",
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'field', type: 'string', example: 'domain'),
                    new OA\Property(property: 'code', type: 'string', example: 'domain_taken'),
                    new OA\Property(property: 'message', type: 'string', example: 'shop.acme.com is already on this engine.'),
                    new OA\Property(property: 'stage', type: 'string', nullable: true, example: 'cloning'),
                    new OA\Property(property: 'deploy_log_offset', type: 'integer', nullable: true, example: 0),
                ],
                type: 'object',
            ),
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SuccessResponse',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
    ],
    type: 'object',
)]
class ApiDefinitions
{
}
