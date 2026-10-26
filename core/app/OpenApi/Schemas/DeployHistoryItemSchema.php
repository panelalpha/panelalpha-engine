<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DeployHistoryItem',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '20261014-101500-a1b2c3'),
        new OA\Property(property: 'status', type: 'string', enum: ['running', 'success', 'partial', 'failed', 'cancelled', 'unknown'], example: 'success'),
        new OA\Property(property: 'error', type: 'string', nullable: true, description: 'Why a failed or cancelled deploy stopped.'),
        new OA\Property(property: 'started_at', type: 'integer', nullable: true, description: 'Unix seconds.'),
        new OA\Property(property: 'finished_at', type: 'integer', nullable: true, description: 'Unix seconds; null while running or when the log ends without a verdict.'),
        new OA\Property(property: 'kind', type: 'string', nullable: true, enum: ['install', 'redeploy'], description: 'install for the deploy a project\'s create ran; null when the log names no source.'),
        new OA\Property(property: 'source', type: 'string', nullable: true, enum: ['git', 'hook', 'archive', 'rebuild', 'template'], example: 'git'),
        new OA\Property(property: 'branch', type: 'string', nullable: true, example: 'main', description: 'The branch the deploy cloned or the checkout was on; null without a git source, and on redeploys logged before the branch was recorded.'),
        new OA\Property(property: 'commit', type: 'string', nullable: true, example: '3f2c9e1d4b5a6c7d8e9f0a1b2c3d4e5f6a7b8c9d'),
        new OA\Property(property: 'trigger', type: 'string', nullable: true, enum: ['api', 'mcp', 'cli', 'hook'], description: 'Null when no task or hook delivery names the deploy.'),
        new OA\Property(property: 'action', type: 'string', nullable: true, example: 'git_pull', description: 'The task\'s action: deploy for a create, rebuild, deploy_archive, attach_source, git_pull, change_branch or revert.'),
        new OA\Property(property: 'task_id', type: 'integer', nullable: true, description: 'The newest task that ran this deploy.'),
    ],
    type: 'object',
)]
class DeployHistoryItemSchema {}
