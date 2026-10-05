<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ModsecCustomRules',
    properties: [
        new OA\Property(property: 'rules', type: 'string', example: 'SecRule REQUEST_URI "@beginsWith /xyz" "id:1100001,phase:1,deny,status:403,log"'),
        new OA\Property(property: 'enabled', type: 'boolean', description: 'Whether the custom ruleset is enabled.', example: false),
        new OA\Property(property: 'id_range', type: 'array', items: new OA\Items(type: 'integer'), example: [1100000, 1199999]),
    ],
    type: 'object',
)]
class ModsecCustomRulesSchema
{
}
