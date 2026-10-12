<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MysqlPrivileges',
    properties: [
        new OA\Property(
            property: 'data',
            type: 'string',
            description: 'The raw GRANT statement for the user on the database, or an empty string when the user has no privileges there.',
            example: "GRANT SELECT, INSERT ON `johndoe_wp`.* TO `johndoe_user`@`localhost`",
        ),
    ],
    type: 'object',
)]
class MysqlPrivilegesSchema
{
}
