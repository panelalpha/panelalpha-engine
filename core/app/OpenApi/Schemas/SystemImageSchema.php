<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SystemImage',
    description: 'An image this host keeps in its cache registry for every project to use: one the operator added '
        .'(`owner: operator`), or one from the engine\'s own catalogue (`owner: engine`).',
    properties: [
        new OA\Property(property: 'id', type: 'integer', nullable: true, example: 3, description: 'The operator image\'s id; null for an engine image.'),
        new OA\Property(property: 'owner', type: 'string', enum: ['operator', 'engine'], example: 'operator'),
        new OA\Property(property: 'ref', type: 'string', example: 'whmcs-dev-base:php8.3', description: 'The name a Dockerfile writes after FROM, or a compose file after image:.'),
        new OA\Property(property: 'source', type: 'string', enum: ['pull', 'build', 'local'], example: 'build', description: '`pull`: pulled from its registry. `build`: built on this host from `dockerfile`. `local`: an image the operator put on the host.'),
        new OA\Property(property: 'why', type: 'string', nullable: true, example: 'WHMCS dev server base'),
        new OA\Property(property: 'dockerfile', type: 'string', nullable: true, description: 'Operator build images only.'),
        new OA\Property(property: 'build_args', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string'), description: 'Operator build images only.'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'ready', 'failed', 'removing'], example: 'ready', description: 'Operator images only: the outcome of the last operation. A failed refresh leaves the previous copy in the registry, so `present` can still be true.'),
        new OA\Property(property: 'present', type: 'boolean', nullable: true, example: true, description: 'Whether the cache registry holds this tag right now; null when the registry could not be read. A project can build FROM it only while true.'),
        new OA\Property(property: 'digest', type: 'string', nullable: true, example: 'sha256:4f1c…', description: 'The manifest digest the cache registry serves; null when not present.'),
        new OA\Property(property: 'size', type: 'integer', nullable: true, example: 231456789, description: 'Compressed bytes a pull moves.'),
        new OA\Property(property: 'refreshed_at', type: 'string', format: 'date-time', nullable: true, description: 'When the operator image last reached the registry.'),
        new OA\Property(property: 'error', type: 'string', nullable: true, description: 'Operator images only: why the last operation failed.'),
        new OA\Property(property: 'task_id', type: 'integer', nullable: true, description: 'Operator images only: the last queued operation.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true, description: 'Operator images only.'),
        new OA\Property(property: 'runtime', type: 'string', nullable: true, description: 'Engine images only.'),
        new OA\Property(property: 'prewarm_id', type: 'string', nullable: true, description: 'Engine images only: the id `pae configure prewarm` selects it by.'),
        new OA\Property(property: 'selected', type: 'boolean', nullable: true, description: 'Engine images only: whether prewarm keeps it ready.'),
    ],
    type: 'object',
)]
class SystemImageSchema {}
