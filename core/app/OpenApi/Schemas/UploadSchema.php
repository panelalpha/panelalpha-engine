<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Upload',
    properties: [
        new OA\Property(property: 'id', type: 'string', description: 'What `source.upload_id` takes.', example: 'upl_01jaxk3m5v9w2c8e4r7t6y1b0n'),
        new OA\Property(property: 'filename', type: 'string', example: 'shop.zip'),
        new OA\Property(property: 'size', type: 'integer', description: 'Bytes.', example: 1048576),
        new OA\Property(property: 'claimed_by', type: 'string', nullable: true, description: 'The project a create gave it to; null until one names it.', example: null),
        new OA\Property(property: 'created_at', type: 'string', nullable: true, example: '2026-10-22T09:00:00+00:00'),
        new OA\Property(property: 'expires_at', type: 'string', description: 'When an unused upload is deleted.', example: '2026-10-23T09:00:00+00:00'),
    ],
    type: 'object',
)]
class UploadSchema {}
