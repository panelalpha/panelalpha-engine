<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'EngineUpdateRun',
    description: 'The most recent engine update, read back from its run directory.',
    properties: [
        new OA\Property(property: 'started_at', type: 'integer', nullable: true, example: 1791242643),
        new OA\Property(
            property: 'finished_at',
            type: 'integer',
            nullable: true,
            example: 1791242712,
            description: 'When the exit code was written; for a run whose process died without one, when it last wrote '
                .'output. Null while it runs.',
        ),
        new OA\Property(property: 'pid', type: 'integer', nullable: true, example: 1273416),
        new OA\Property(property: 'exit_code', type: 'integer', nullable: true, example: 0, description: 'Null while it runs, and for a run whose process died'),
        new OA\Property(
            property: 'failed',
            type: 'boolean',
            example: false,
            description: 'True when the run exited non-zero, or ended without writing an exit code: its process is gone, '
                .'or it never started within the grace period. `reason` then says why.',
        ),
        new OA\Property(
            property: 'result',
            type: 'string',
            nullable: true,
            enum: ['installed', 'updated', 'up_to_date', 'refused', 'rolled_back', 'failed'],
            example: 'up_to_date',
            description: 'What the run did, as it recorded it: `up_to_date` changed nothing because the target is the '
                .'running release at the same commit; `refused` changed nothing because the target is older, or its version could not '
                .'be read; `rolled_back` failed after replacing the engine\'s files and put the previous release back. '
                .'Null while it runs, and for a run that died or an installer that records none.',
        ),
        new OA\Property(
            property: 'reason',
            type: 'string',
            nullable: true,
            example: 'Already up to date: 2.3.0 (b9f8c105) is running and main is the same commit. Nothing was changed. Pass --reinstall to install it again.',
            description: 'Why the run ended as it did: the reason it recorded (for an update within the release, '
                .'both commits), else, for a failed run, its exit code, '
                .'that its process ended without writing one, or that the job never started.',
        ),
        new OA\Property(property: 'tail_stdout', type: 'string', nullable: true, description: 'The last lines of its output'),
        new OA\Property(property: 'tail_stderr', type: 'string', nullable: true, description: 'The last lines of its error output'),
        new OA\Property(property: 'from_version', type: 'string', nullable: true, example: '2.3.0', description: 'The release that was running when the run started'),
        new OA\Property(property: 'to_version', type: 'string', nullable: true, example: '2.3.1', description: 'The release the run was installing'),
        new OA\Property(property: 'logs_path', type: 'string', example: '/opt/panelalpha/log/engine-updates/latest'),
    ],
    type: 'object',
)]
class EngineUpdateRunSchema {}
