<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Backup',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'username', type: 'string', example: 'myproject'),
        new OA\Property(property: 'container_id', type: 'integer', example: 1),
        new OA\Property(property: 'kind', type: 'string', enum: ['manual', 'scheduled'], example: 'manual'),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'contents', type: 'object', nullable: true,
            properties: [
                new OA\Property(property: 'files', type: 'boolean'),
                new OA\Property(property: 'databases', type: 'boolean'),
            ]),
        new OA\Property(property: 'async_status', type: 'object', nullable: true,
            description: 'Where each operation on the backup stands, and the task that runs it.',
            properties: [
                new OA\Property(property: 'backup', type: 'string', enum: ['pending', 'running', 'completed', 'failed']),
                new OA\Property(property: 'restore', type: 'string', enum: ['pending', 'running', 'completed', 'failed'],
                    description: 'The latest restore; absent until one is asked for.'),
                new OA\Property(property: 'delete', type: 'string', enum: ['pending', 'running', 'failed'],
                    description: 'Absent until a delete is asked for; a deleted backup is gone.'),
                new OA\Property(property: 'source', type: 'string', enum: ['api', 'schedule', 'artisan'], description: 'What asked for the backup.'),
                new OA\Property(property: 'backup_task_id', type: 'integer', description: 'The task that takes the backup.'),
                new OA\Property(property: 'restore_task_id', type: 'integer', description: 'The latest restore\'s task.'),
                new OA\Property(property: 'delete_task_id', type: 'integer', description: 'The delete\'s task.'),
                new OA\Property(property: 'task_id', type: 'integer',
                    description: 'The task of the latest of those operations. `project:backup:create`, `project:backup:restore` and '
                        .'`project:backup:delete` run in-process with no task, and set or move none of these ids.'),
            ]),
        new OA\Property(property: 'error', type: 'string', nullable: true),
        new OA\Property(property: 'size_bytes', type: 'integer', example: 1048576),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'container', ref: '#/components/schemas/BackupContainer', nullable: true),
    ],
    type: 'object',
)]
class BackupSchema {}
