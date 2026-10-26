<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'BackupContainer',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 's3-backups'),
        new OA\Property(property: 'driver', type: 'string', enum: ['local', 's3', 'ftp', 'ftps', 'sftp'], example: 's3'),
        new OA\Property(property: 'location', type: 'string', example: 'my-bucket/backups'),
        new OA\Property(property: 'has_credentials', type: 'boolean', example: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
class BackupContainerSchema {}
