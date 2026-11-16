<?php

namespace App\Lib\Project\Create;

use App\Lib\Project\CreateInspection;
use App\Models\Task;

/**
 * A create or source change accepted and queued: the task that deploys it,
 * and what the repository inspection found when one ran.
 */
final class QueuedDeploy
{
    public function __construct(
        public readonly Task $task,
        public readonly ?CreateInspection $inspection = null,
    ) {}
}
