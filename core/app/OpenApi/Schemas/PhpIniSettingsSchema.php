<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PhpIniSettings',
    properties: [
        new OA\Property(
            property: 'data',
            description: 'Each directive with its value as written in the file; an empty object when there are none.',
            type: 'object',
            example: ['memory_limit' => '256M', 'upload_max_filesize' => '64M'],
        ),
    ],
    type: 'object',
)]
class PhpIniSettingsSchema {}
