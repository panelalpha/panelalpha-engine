<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'HostScriptReport',
    description: 'Whether the root broker could run one of the engine\'s scripts on the host the last time it tried. It runs '
        .'one only while root alone can change it and every directory above it.',
    properties: [
        new OA\Property(property: 'failing', type: 'boolean', example: false),
        new OA\Property(
            property: 'warning',
            type: 'string',
            nullable: true,
            description: 'What was refused or failed, what that leaves undone and the command that fixes it, as the root '
                .'broker logged it; null while the script runs.'
        ),
    ],
    type: 'object',
)]
class HostScriptReportSchema {}
