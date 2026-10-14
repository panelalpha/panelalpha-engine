<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'BackupSchedule',
    properties: [
        new OA\Property(property: 'container_id', type: 'integer', example: 1),
        new OA\Property(property: 'cron', type: 'string', example: '0 3 * * *'),
        new OA\Property(property: 'keep_last', type: 'integer', example: 7),
        new OA\Property(property: 'files', type: 'boolean', example: true),
        new OA\Property(property: 'databases', type: 'boolean', example: true),
        new OA\Property(property: 'enabled', type: 'boolean', example: true),
        new OA\Property(property: 'next_run_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'last_run_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'last_backup_id', type: 'integer', nullable: true),
        new OA\Property(property: 'last_error', type: 'string', nullable: true),
    ],
    type: 'object',
)]
class BackupScheduleSchema
{
}
