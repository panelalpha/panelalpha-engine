<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MysqlPrivileges',
    properties: [
        new OA\Property(
            property: 'data',
            type: 'string',
            description: 'The privileges the user holds on the database (`SELECT, INSERT`, `ALL PRIVILEGES`, …), or an empty string when the user has none there.',
            example: 'SELECT, INSERT',
        ),
    ],
    type: 'object',
)]
class MysqlPrivilegesSchema {}
