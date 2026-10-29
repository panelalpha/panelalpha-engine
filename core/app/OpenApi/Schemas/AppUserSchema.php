<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AppUser',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '1', description: 'The app assigns its own user id, so it is a string.'),
        new OA\Property(property: 'username', type: 'string', example: 'johndoe', nullable: true),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com', nullable: true),
        new OA\Property(property: 'role', type: 'string', example: 'administrator', nullable: true),
    ],
    type: 'object',
)]
class AppUserSchema {}
