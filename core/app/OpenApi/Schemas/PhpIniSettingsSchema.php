<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PhpIniSettings',
    properties: [
        new OA\Property(
            property: 'settings',
            type: 'object',
            example: ['memory_limit' => '256M', 'upload_max_filesize' => '64M'],
        ),
    ],
    type: 'object',
)]
class PhpIniSettingsSchema {}
