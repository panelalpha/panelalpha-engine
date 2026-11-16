<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'BackupRestoreFilter',
    description: 'Parts of a backup, as its `items` name them in `details`.',
    properties: [
        new OA\Property(property: 'files', type: 'boolean', description: 'The project files.'),
        new OA\Property(property: 'databases', type: 'array', items: new OA\Items(type: 'string'), example: ['shop'],
            description: 'Database names, each the `details.name` of a database item.'),
        new OA\Property(property: 'volumes', type: 'array', items: new OA\Items(type: 'string'), example: ['app_data'],
            description: 'Volume names, each the `details.name` of a volume item. A container project only; on PHP hosting 422 `volumes_not_applicable`.'),
    ],
    type: 'object',
)]
class BackupRestoreFilterSchema {}
