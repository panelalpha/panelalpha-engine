<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PhpErrorLog',
    properties: [
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'file', type: 'string', nullable: true, example: 'php_error.log'),
        new OA\Property(property: 'size', type: 'integer', example: 12345),
        new OA\Property(property: 'mtime', type: 'integer', nullable: true, example: 1760176801),
        new OA\Property(property: 'truncated', type: 'boolean', example: true),
    ],
    type: 'object',
)]
class PhpErrorLogSchema {}
