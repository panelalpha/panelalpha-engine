<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProjectCreateSettings',
    description: 'Hosting settings, stored as given.',
    properties: [
        new OA\Property(property: 'php_fpm_pool_settings', type: 'string', nullable: true),
        new OA\Property(property: 'lsphp_settings', type: 'string', nullable: true),
        new OA\Property(property: 'redis_config', type: 'string', nullable: true),
    ],
    type: 'object',
)]
class ProjectCreateSettingsSchema {}
