<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UsageQuota',
    properties: [
        new OA\Property(property: 'usage', type: 'integer', example: 123456),
        new OA\Property(property: 'maximum', type: 'integer', nullable: true, example: 10485760),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Usage',
    properties: [
        new OA\Property(property: 'storage', ref: '#/components/schemas/UsageQuota'),
        new OA\Property(
            property: 'bandwidth',
            ref: '#/components/schemas/UsageQuota',
            description: 'Transfer for the current calendar month in the host timezone, in bytes. maximum is the project bandwidth_limit in bytes, or null when unlimited.',
        ),
        new OA\Property(property: 'addon_domains', ref: '#/components/schemas/UsageQuota'),
        new OA\Property(property: 'subdomains', ref: '#/components/schemas/UsageQuota'),
        new OA\Property(property: 'ftp_accounts', ref: '#/components/schemas/UsageQuota'),
        new OA\Property(property: 'sftp_accounts', ref: '#/components/schemas/UsageQuota'),
        new OA\Property(property: 'mysql_databases', ref: '#/components/schemas/UsageQuota'),
    ],
    type: 'object',
)]
class UsageSchema
{
}
