<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ModsecRuleset',
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'OWASP-CRS'),
        new OA\Property(property: 'enabled', type: 'boolean', example: true),
        new OA\Property(
            property: 'config_files',
            type: 'array',
            items: new OA\Items(type: 'string'),
            nullable: true,
        ),
    ],
    type: 'object',
)]
class ModsecRulesetSchema {}
