<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'EnvVar',
    properties: [
        new OA\Property(property: 'key', type: 'string', example: 'APP_ENV'),
        new OA\Property(
            property: 'value',
            type: 'string',
            nullable: true,
            example: 'production',
            description: 'The value, null unless the request asked for `reveal=1`. Always null for a value set from a `vault:<key>`.'
        ),
        new OA\Property(
            property: 'masked',
            type: 'boolean',
            example: true,
            description: 'True when the value is withheld: without `reveal=1`, and for a value set from a `vault:<key>` even with it.'
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'EnvVars',
    properties: [
        new OA\Property(
            property: 'vars',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/EnvVar')
        ),
        new OA\Property(
            property: 'applied_keys',
            type: 'array',
            items: new OA\Items(type: 'string'),
            example: ['APP_ENV', 'APP_KEY'],
            description: 'The keys the last deploy merged into the application environment.'
        ),
    ],
    type: 'object',
)]
class EnvVarsSchema {}
