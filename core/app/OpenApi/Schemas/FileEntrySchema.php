<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'FileEntry',
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'wp-config.php'),
        new OA\Property(property: 'path', type: 'string', example: '/example.com/public_html/wp-config.php'),
        new OA\Property(property: 'type', type: 'string', enum: ['file', 'dir', 'link'], example: 'file'),
        new OA\Property(property: 'size', type: 'integer', example: 3120),
        new OA\Property(property: 'mtime', type: 'integer', example: 1760140000),
        new OA\Property(property: 'mode', type: 'string', example: '0644'),
        new OA\Property(property: 'uid', type: 'integer', example: 1003),
        new OA\Property(property: 'gid', type: 'integer', example: 1003),
        new OA\Property(property: 'target', type: 'string', nullable: true, example: 'releases/5'),
        new OA\Property(property: 'target_type', type: 'string', enum: ['file', 'dir', 'missing'], nullable: true, example: 'dir'),
        new OA\Property(property: 'has_subdirectories', type: 'boolean', nullable: true, example: true),
    ],
    type: 'object',
)]
class FileEntrySchema
{
}
