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
            description: 'The keys the last deploy or env var apply delivered to the application: merged into the project\'s `.env`, set in the app service\'s `environment:` of a run file the engine generated, or loaded from `.env.panelalpha` beside a `.env` the repository tracks. A key merged into `.env` is listed even when the project\'s own compose file does not read `.env`.'
        ),
    ],
    type: 'object',
)]
class EnvVarsSchema {}
