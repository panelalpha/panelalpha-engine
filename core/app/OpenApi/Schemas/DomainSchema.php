<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Domain',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'domain', type: 'string', example: 'johndoe.example.com'),
        new OA\Property(property: 'type', type: 'string', example: 'main', enum: ['main', 'addon', 'subdomain']),
        new OA\Property(property: 'document_root', type: 'string', example: '/home/johndoe/public_html', nullable: true),
        new OA\Property(property: 'php_version', type: 'string', example: '8.2', nullable: true),
        new OA\Property(property: 'redirect_url', type: 'string', nullable: true),
        new OA\Property(property: 'ssl', type: 'boolean', example: true),
        new OA\Property(property: 'details', type: 'object', properties: [
            new OA\Property(property: 'https_ports', type: 'array', items: new OA\Items(type: 'integer'), example: [443, 8443], description: 'Ports the domain answers HTTPS on'),
            new OA\Property(property: 'https_port', type: 'integer', example: 443, description: 'The one the project\'s public URL names when this is its main domain'),
        ]),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
class DomainSchema {}
