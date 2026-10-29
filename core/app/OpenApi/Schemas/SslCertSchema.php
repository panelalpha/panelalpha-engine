<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SslCert',
    properties: [
        new OA\Property(property: 'domain', type: 'string', example: 'johndoe.example.com'),
        new OA\Property(property: 'issuer', type: 'string', example: "Let's Encrypt"),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'valid_to', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'subject', type: 'string', nullable: true),
    ],
    type: 'object',
)]
class SslCertSchema {}
