<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'EngineUpdateCheck',
    description: 'The running engine release against the newest engine release the PanelAlpha Hub publishes.',
    properties: [
        new OA\Property(property: 'running', type: 'object', properties: [
            new OA\Property(property: 'version', type: 'string', nullable: true, example: '2.3.1'),
        ]),
        new OA\Property(property: 'available', type: 'object', nullable: true, description: 'Null when the newest release could not be read', properties: [
            new OA\Property(property: 'version', type: 'string', example: '2.4.0'),
            new OA\Property(property: 'published_at', type: 'string', format: 'date', nullable: true, example: '2026-11-19'),
            new OA\Property(property: 'url', type: 'string', nullable: true, example: 'https://engine.panelalpha.com/changelog', description: 'Where the release is described'),
            new OA\Property(property: 'notes', type: 'string', nullable: true, description: 'The release notes, in Markdown'),
        ]),
        new OA\Property(
            property: 'status',
            type: 'string',
            enum: ['update_available', 'up_to_date', 'older_available', 'unknown'],
            example: 'update_available',
            description: '`update_available`: a newer release. `up_to_date`: the running release is the newest. '
                .'`older_available`: the newest release is older than the running one, and an update refuses it. '
                .'`unknown`: the newest release could not be read; `reason` says why.',
        ),
        new OA\Property(property: 'update_available', type: 'boolean', example: true, description: 'True when PUT /system/update would install a newer release'),
        new OA\Property(property: 'reason', type: 'string', nullable: true, description: 'Why the status is `unknown`'),
        new OA\Property(property: 'checked_at', type: 'integer', example: 1795000000, description: 'When the PanelAlpha Hub was asked'),
        new OA\Property(property: 'cached', type: 'boolean', example: false, description: 'True when the newest release is an earlier answer, kept for up to an hour'),
    ],
    type: 'object',
)]
class EngineUpdateCheckSchema {}
