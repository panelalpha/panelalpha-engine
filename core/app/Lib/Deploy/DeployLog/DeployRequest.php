<?php

namespace App\Lib\Deploy\DeployLog;

use App\Lib\Task\TaskTrigger;
use App\Models\Task;

/**
 * Who asked for a deploy, written into its log, which outlives the task and the hook delivery.
 */
final class DeployRequest
{
    /**
     * The deploy on the task that runs it, which a reconciler and a cancel match the task to its log by;
     * and the task with who asked for it in the log.
     */
    public static function recordTask(?DeployLogger $logger, ?Task $task): void
    {
        if ($logger === null || $task === null) {
            return;
        }
        $task->mergeDetails(['deploy_id' => $logger->getDeployId()]);
        self::write($logger, $task->id, $task->detailText('trigger'), $task->detailText('action'));
    }

    /** A deploy this process runs without a task: the surface serving the process asked for it. */
    public static function record(?DeployLogger $logger, string $action): void
    {
        if ($logger === null) {
            return;
        }
        self::write($logger, null, TaskTrigger::current(), $action);
    }

    private static function write(DeployLogger $logger, ?int $taskId, ?string $trigger, ?string $action): void
    {
        $line = DeployStartLine::requestText($taskId, $trigger, $action);
        if ($line !== null) {
            $logger->dim($line);
        }
    }
}
