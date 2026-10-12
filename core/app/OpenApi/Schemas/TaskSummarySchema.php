<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TaskSummary',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 42),
        new OA\Property(property: 'job_id', type: 'string', nullable: true),
        new OA\Property(property: 'queue', type: 'string', example: 'default'),
        new OA\Property(property: 'job_type', type: 'string', example: 'App\\Jobs\\DeployProject'),
        new OA\Property(property: 'status', type: 'string', enum: ['queued', 'running', 'completed', 'failed', 'cancelled']),
        new OA\Property(property: 'details', type: 'object', nullable: true),
        new OA\Property(property: 'username', type: 'string', nullable: true, example: 'alice'),
        new OA\Property(property: 'pid', type: 'integer', nullable: true),
        new OA\Property(property: 'pid_start_time', type: 'string', nullable: true),
        new OA\Property(property: 'queued_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'failed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'action', type: 'string', nullable: true, example: 'staging'),
        new OA\Property(property: 'created_by_token_id', type: 'integer', nullable: true),
    ],
    type: 'object',
)]
class TaskSummarySchema
{
}
