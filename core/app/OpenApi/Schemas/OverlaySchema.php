<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Overlay',
    required: ['html', 'position'],
    properties: [
        new OA\Property(property: 'html', type: 'string', description: 'The HTML written into every HTML page.', example: '<div style="position:fixed;bottom:0;left:0;right:0;padding:8px;background:#222;color:#fff;text-align:center">Maintenance tonight at 22:00</div>'),
        new OA\Property(property: 'position', type: 'string', enum: ['body_end', 'head_end'], description: 'Written just before </body> or just before </head>.', example: 'body_end'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ProjectOverlay',
    required: ['html', 'position', 'show_global', 'global', 'active'],
    properties: [
        new OA\Property(property: 'html', type: 'string', nullable: true, description: 'The project\'s own overlay HTML, or null when it has none.'),
        new OA\Property(property: 'position', type: 'string', enum: ['body_end', 'head_end'], nullable: true),
        new OA\Property(property: 'show_global', type: 'boolean', description: 'Whether the global overlay is written into this project\'s pages too.', example: true),
        new OA\Property(property: 'global', ref: '#/components/schemas/Overlay', nullable: true, description: 'The global overlay, whether or not this project shows it.'),
        new OA\Property(property: 'active', type: 'boolean', description: 'Whether any overlay is written into this project\'s pages.', example: true),
    ],
    type: 'object',
)]
class OverlaySchema {}
