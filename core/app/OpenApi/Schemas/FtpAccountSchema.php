<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'FtpAccount',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'user_id', type: 'integer', example: 1),
        new OA\Property(property: 'user', type: 'string', example: 'ftpuser1@example.com'),
        new OA\Property(property: 'directory', type: 'string', example: '/public_html', description: 'Relative to the project home.'),
        new OA\Property(
            property: 'details',
            type: 'object',
            properties: [
                new OA\Property(property: 'unlimited_quota', type: 'boolean', description: 'True when the account has no quota of its own. Read this, not `quota`, to tell an unlimited account.'),
                new OA\Property(property: 'quota', type: 'integer', nullable: true, example: 1024, description: "Quota in MB. For an unlimited account, the project's disk space limit (-1 when its disk space is unlimited)."),
            ],
        ),
        new OA\Property(property: 'disk_usage_mb', type: 'string', example: '13'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
class FtpAccountSchema {}
