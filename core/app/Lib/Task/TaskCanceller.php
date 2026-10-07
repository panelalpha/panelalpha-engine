<?php

namespace App\Lib\Task;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\ProcessIdentity;
use App\Models\Task;

class TaskCanceller
{
    /** @var callable */
    private $isStillRunning;

    /**
     * @param null|callable(mixed, mixed): bool $isStillRunning
     */
    public function __construct(private ProcessTreeKiller $killer, mixed $isStillRunning = null)
    {
        $this->isStillRunning = $isStillRunning ?? [ProcessIdentity::class, 'isStillRunning'];
    }

    /**
     * @return array{cancelled: bool, message?: string}
     */
    public function cancel(Task $task): array
    {
        if ($task->isTerminal()) {
            return [
                'cancelled' => false,
                'message' => 'Task cannot be cancelled',
            ];
        }

        $wasRunning = $task->status === Task::STATUS_RUNNING;
        $task->markCancelled();

        // The deploy subprocess pid comes from the deploy log, not from
        // `tasks.pid`: only the streaming deploy writes that column, so for
        // every other job the log is where a pid exists at all.
        // `requestCancel()` already returns it -- it reads latest.json, where
        // `Shell::streamProcess()` recorded it -- and this used to discard the
        // return value and test the never-populated `$task->pid` instead, so
        // cancelling a deploy never killed anything.
        //
        // Note the log pid is deliberately not corroborated with
        // `pid_start_time`: a *running* deploy has no recorded start time to
        // check against, and the window this opens is the same one
        // `requestCancel()` already accepted when it wrote the status.
        //
        // Only the deploy this task is running. A queued task runs none, and
        // the account's latest log may be another's: a deploy started without
        // a task, or a create's log still waiting for its files.
        $pid = null;
        $log = $wasRunning ? TaskReconciler::deployLogOf($task) : null;
        if ($log !== null && ($log['status'] ?? null) === DeployLogger::STATUS_RUNNING) {
            $pid = DeployLogger::requestCancel((string) $task->username)['pid'];
        }

        // Fall back to the task's own pid for the job types that do set it,
        // still checking the recorded start time so a reused pid is never
        // signalled.
        if ($pid === null && $task->pid !== null
            && ($this->isStillRunning)($task->pid, $task->pid_start_time)) {
            $pid = $task->pid;
        }

        if (is_int($pid) && $pid > 0) {
            $this->killer->kill($pid);
        }

        return ['cancelled' => true];
    }

    /**
     * Cancel a task only while it is still queued. Its job then exits before
     * doing anything ({@see \App\Jobs\Concerns\AttachTask::runTask()}), so
     * there is no process to stop. False when a worker took it first.
     */
    public function cancelQueued(Task $task): bool
    {
        return $task->cancelIfQueued();
    }
}
