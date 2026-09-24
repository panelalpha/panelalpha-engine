<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProjectSetting',
    properties: [
        new OA\Property(property: 'key', type: 'string', example: 'cloudflare-api-token'),
        new OA\Property(property: 'value', type: 'string', nullable: true, description: 'Redacted when the setting is a secret.', example: 'abcd********wxyz'),
        new OA\Property(property: 'set', type: 'boolean', description: 'Whether a value is present.', example: true),
        new OA\Property(property: 'secret', type: 'boolean', example: true),
    ],
    type: 'object',
)]
class ProjectSettingSchema
{
}
