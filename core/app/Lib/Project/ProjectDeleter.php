<?php

namespace App\Lib\Project;

use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\ProjectBusyException;
use App\Jobs\CreateStaging;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Task\ProcessTreeKiller;
use App\Lib\Task\TaskCanceller;
use App\Lib\Task\TaskReconciler;
use App\Models\Task;
use App\Models\User;
use App\System;
use Illuminate\Database\Eloquent\Builder;

/**
 * Deleting a project (REST, MCP, `project:delete`) never overlaps a job that
 * works on its account. Queued jobs are cancelled before anything is torn
 * down; a running one -- a task, a deploy, a push -- refuses the delete.
 *
 * The account's deploy lock is held for the whole delete, so a deploy,
 * rebuild or push to deploy that arrives meanwhile is refused as well.
 */
class ProjectDeleter
{
    /** The longest a job may run (its $timeout); a cancelled one older than that is gone. */
    private const STOPPING_WINDOW_SECONDS = 7200;

    private TaskCanceller $canceller;

    /** @var callable(Task): ?bool */
    private $inQueue;

    /**
     * @param null|callable(Task): ?bool $inQueue whether a task's job is still queued or
     *        reserved by a worker; {@see TaskReconciler::queueCheck()} by default
     */
    public function __construct(?TaskCanceller $canceller = null, ?callable $inQueue = null)
    {
        $this->canceller = $canceller ?? new TaskCanceller(new ProcessTreeKiller(new System()));
        $this->inQueue = $inQueue ?? TaskReconciler::queueCheck();
    }

    /**
     * @throws ProjectBusyException
     */
    public function delete(User $user): void
    {
        try {
            $lock = DeployLock::acquireFor($user->username);
        } catch (DeployAlreadyRunningException) {
            // Named after the task holding it, when a task does.
            $this->assertIdle($user, lockHeldElsewhere: true);
            throw ProjectBusyException::deployRunning($user->username);
        }

        try {
            $this->assertIdle($user);
            $user->project()->assertDeletable();
            $this->cancelQueued($user);
            $this->destroy($user);
        } finally {
            $lock->release();
        }
    }

    protected function destroy(User $user): void
    {
        $user->project()->destroy();
    }

    /**
     * @param bool $lockHeldElsewhere the account's deploy lock is held, so not by this delete
     * @throws ProjectBusyException
     */
    private function assertIdle(User $user, bool $lockHeldElsewhere = false): void
    {
        // A task whose worker died is stopped, though its row may still say running and
        // its queue row stays reserved for a day. Unless the lock is held elsewhere: a
        // build that worker started may be what holds it.
        $working = static fn (Task $task): bool => $lockHeldElsewhere || !TaskReconciler::workerGone($task);

        $running = $this->tasksOf($user)->where('status', Task::STATUS_RUNNING)->orderBy('id')->get()->first($working);
        if ($running !== null) {
            throw ProjectBusyException::taskRunning($user->username, $running);
        }

        // A cancel marks the task at once; its job may still be at work.
        $cancelled = $this->tasksOf($user)
            ->where('status', Task::STATUS_CANCELLED)
            ->whereNotNull('job_id')
            ->where('cancelled_at', '>=', now()->subSeconds(self::STOPPING_WINDOW_SECONDS))
            ->orderBy('id')
            ->get();
        foreach ($cancelled as $task) {
            if ($working($task) && ($this->inQueue)($task) === true) {
                throw ProjectBusyException::taskStopping($user->username, $task);
            }
        }

        // A push has no task: its target carries the flag Projects::assertIdle() reads.
        foreach ([$user, $user->liveUser, $user->stagingUser] as $side) {
            if ($side !== null && ($side->asyncStatus()['push'] ?? null) === 'running') {
                throw ProjectBusyException::pushRunning($user->username);
            }
        }
    }

    /**
     * @throws ProjectBusyException
     */
    private function cancelQueued(User $user): void
    {
        $queued = Task::query()
            ->where('username', $user->username)
            ->where('status', Task::STATUS_QUEUED)
            ->orderBy('id')
            ->get();
        foreach ($queued as $task) {
            if (!$this->canceller->cancelQueued($task)) {
                // A worker took it since the check above.
                $this->assertIdle($user);
            }
        }
    }

    /**
     * The project's own tasks, and a staging copy reading from it: the copy's
     * task is the destination's, the source named in its details.
     *
     * @return Builder<Task>
     */
    private function tasksOf(User $user): Builder
    {
        return Task::query()->where(static function (Builder $query) use ($user): void {
            $query->where('username', $user->username)
                ->orWhere(static function (Builder $copy) use ($user): void {
                    $copy->where('job_type', CreateStaging::class)
                        ->where('details->source', $user->username);
                });
        });
    }
}
