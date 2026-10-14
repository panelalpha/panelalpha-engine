<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'EngineUpdateRun',
    description: 'The most recent engine update, read back from its run directory.',
    properties: [
        new OA\Property(property: 'started_at', type: 'integer', nullable: true, example: 1791242643),
        new OA\Property(property: 'finished_at', type: 'integer', nullable: true, example: 1791242712, description: 'When the exit code was written; null until then'),
        new OA\Property(property: 'pid', type: 'integer', nullable: true, example: 1273416),
        new OA\Property(property: 'exit_code', type: 'integer', nullable: true, example: 0),
        new OA\Property(
            property: 'failed',
            type: 'boolean',
            example: false,
            description: 'True when the run exited non-zero, or ended without writing an exit code: its process is gone, '
                . 'or it never started within the grace period. The tails then say why.',
        ),
        new OA\Property(
            property: 'reason',
            type: 'string',
            nullable: true,
            example: 'the update job never started',
            description: 'Why a failed run failed when neither output tail says it: the job never started, or its '
                . 'process ended without writing an exit code.',
        ),
        new OA\Property(property: 'tail_stdout', type: 'string', nullable: true, description: 'The last lines of its output'),
        new OA\Property(property: 'tail_stderr', type: 'string', nullable: true, description: 'The last lines of its error output'),
        new OA\Property(property: 'from_version', type: 'string', nullable: true),
        new OA\Property(property: 'to_version', type: 'string', nullable: true),
        new OA\Property(property: 'logs_path', type: 'string', example: '/opt/panelalpha/log/engine-updates/latest'),
    ],
    type: 'object',
)]
class EngineUpdateRunSchema
{
}
