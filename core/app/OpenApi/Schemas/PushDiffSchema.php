<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PushDiff',
    properties: [
        new OA\Property(
            property: 'tables_structure_diff',
            description: 'Source tables whose definition differs from the target, or that the target does not hold.',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/PushTableDiff'),
        ),
        new OA\Property(
            property: 'tables_data_diff',
            description: 'Source tables whose row checksum differs from the target.',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/PushTableDiff'),
        ),
    ],
    type: 'object',
)]
class PushDiffSchema
{
}
