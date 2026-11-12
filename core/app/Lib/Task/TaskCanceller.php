<?php

namespace App\Lib\Task;

use App\Jobs\Concerns\AttachTask;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\ProcessIdentity;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class TaskCanceller
{
    /** @var callable */
    private $isStillRunning;

    /** @var callable */
    private $cancelDeploy;

    /**
     * @param  null|callable(mixed, mixed): bool  $isStillRunning
     * @param  null|callable(Task): void  $cancelDeploy
     */
    public function __construct(private ProcessTreeKiller $killer, mixed $isStillRunning = null, mixed $cancelDeploy = null)
    {
        $this->isStillRunning = $isStillRunning ?? [ProcessIdentity::class, 'isStillRunning'];
        $this->cancelDeploy = $cancelDeploy ?? [self::class, 'tearDownHalfStartedApp'];
    }

    /**
     * Marks the task cancelled and stops its process, when it has one. A task the worker finished or took since it
     * was read is answered like a terminal one.
     *
     * @return array{cancelled: bool, message?: string}
     */
    public function cancel(Task $task): array
    {
        if ($task->isTerminal()) {
            return self::refused('Task cannot be cancelled');
        }

        $wasRunning = $task->status === Task::STATUS_RUNNING;

        if ($wasRunning && self::cannotBeStopped($task)) {
            return self::refused('This task is already running and cannot be stopped; wait for it to finish.');
        }

        $cancelled = $wasRunning ? $task->markCancelled() : $this->cancelQueued($task);
        if (! $cancelled) {
            return self::refused('Task cannot be cancelled');
        }

        $deploy = $this->cancelOwnDeploy($task, $wasRunning);
        $pid = $deploy['pid'] ?? $this->ownPid($task);
        if (is_int($pid) && $pid > 0) {
            $this->killer->kill($pid);
        }

        if ($deploy['cancelled'] ?? false) {
            ($this->cancelDeploy)($task);
        }

        return ['cancelled' => true];
    }

    /**
     * Best-effort teardown of what a cancelled deploy had already started: it
     * never destroys the account, so a failure is only logged.
     */
    private static function tearDownHalfStartedApp(Task $task): void
    {
        try {
            $user = is_string($task->username) ? User::findByUsername($task->username) : null;
            $user?->project()->cancelRunningDeploy();
        } catch (\Throwable $e) {
            Log::warning("Deploy cancel cleanup failed for {$task->username}: {$e->getMessage()}");
        }
    }

    /**
     * @return array{cancelled: false, message: string}
     */
    private static function refused(string $message): array
    {
        return [
            'cancelled' => false,
            'message' => $message,
        ];
    }

    /**
     * Nothing can stop a running job without a pid (backup, restore, staging, push):
     * marking it cancelled would hide its real outcome.
     */
    private static function cannotBeStopped(Task $task): bool
    {
        return ! $task->isDeploy() && $task->pid === null;
    }

    /**
     * Cancels the deploy this task runs, by its log, and names the pid that log streams, as only that deploy writes
     * `tasks.pid`. Null when it runs none: a queued task, or the latest log is another's or not running.
     *
     * @return ?array{cancelled: bool, pid: ?int}
     */
    private function cancelOwnDeploy(Task $task, bool $wasRunning): ?array
    {
        $log = $wasRunning ? TaskReconciler::deployLogOf($task) : null;
        if ($log === null || ($log['status'] ?? null) !== DeployLogger::STATUS_RUNNING) {
            return null;
        }

        return DeployLogger::requestCancel((string) $task->username);
    }

    /** The task's own pid, while its recorded start time still matches, so a reused pid is never signalled. */
    private function ownPid(Task $task): ?int
    {
        if ($task->pid !== null && ($this->isStillRunning)($task->pid, $task->pid_start_time)) {
            return $task->pid;
        }

        return null;
    }

    /**
     * Cancel a task only while it is still queued: its job then exits before doing anything ({@see AttachTask::runTask()}),
     * so there is no process to stop. False when a worker took it first.
     */
    public function cancelQueued(Task $task): bool
    {
        return $task->cancelIfQueued();
    }
}
