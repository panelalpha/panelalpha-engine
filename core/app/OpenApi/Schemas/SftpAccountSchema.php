<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SftpAccount',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'user_id', type: 'integer', example: 1),
        new OA\Property(property: 'username', type: 'string', example: 'johndoe_sftp'),
        new OA\Property(property: 'auth_method', type: 'string', example: 'password,public_key', description: 'Comma-separated list of "password" and/or "public_key". A method left out is turned off.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ],
    type: 'object',
)]
class SftpAccountSchema {}
