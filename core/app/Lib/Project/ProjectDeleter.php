<?php

namespace App\Lib\Project;

use App\Auth\TokenAbilities;
use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\ProjectBusyException;
use App\Jobs\CreateStaging;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Task\ProcessTreeKiller;
use App\Lib\Task\TaskCanceller;
use App\Lib\Task\TaskReconciler;
use App\Models\PersonalAccessToken;
use App\Models\Task;
use App\Models\User;
use App\Support\JobBudget;
use App\System;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

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
    private const STOPPING_WINDOW_SECONDS = JobBudget::SECONDS;

    private TaskCanceller $canceller;

    private ApiAccess $apiAccess;

    /** @var callable(Task): ?bool */
    private $inQueue;

    /**
     * @param  null|callable(Task): ?bool  $inQueue  whether a task's job is still queued or
     *                                               reserved by a worker; {@see TaskReconciler::queueCheck()} by default
     */
    public function __construct(
        ?TaskCanceller $canceller = null,
        ?callable $inQueue = null,
        ?ApiAccess $apiAccess = null,
    ) {
        $this->canceller = $canceller ?? new TaskCanceller(new ProcessTreeKiller(new System));
        $this->inQueue = $inQueue ?? TaskReconciler::queueCheck();
        $this->apiAccess = $apiAccess ?? new ApiAccess;
    }

    /**
     * A lock held elsewhere is reported as the task holding it, when a task does.
     *
     * @throws ProjectBusyException
     */
    public function delete(User $user): void
    {
        try {
            $lock = DeployLock::acquireFor($user->username, DeployLock::HOLDER_DELETE);
        } catch (DeployAlreadyRunningException) {
            $this->assertIdle($user, lockHeldElsewhere: true);
            throw ProjectBusyException::lockHeld($user->username);
        }

        try {
            $this->assertIdle($user);
            $user->project()->assertDeletable();
            $this->cancelQueued($user);
            $this->releaseApiAccess($user);
            $this->destroy($user);
            $this->forgetTokenScopes($user->username);
        } finally {
            $lock->release();
        }
    }

    protected function destroy(User $user): void
    {
        $user->project()->destroy();
    }

    /**
     * The account is going away, so its Engine API allowance and token go with
     * it. Nothing is applied: the lock this delete holds is the one an apply
     * would need, and the next firewall apply drops the rule anyway.
     */
    private function releaseApiAccess(User $user): void
    {
        try {
            $this->apiAccess->deny($user, apply: false);
        } catch (\Throwable $e) {
            $this->assertApiAccessReleased($user, $e);
            Log::warning("Could not remove the Engine API access of '{$user->username}': ".$e->getMessage());
        }
    }

    /**
     * An allowance that outlived its project would hand its token to the next
     * project created under the same name, so the delete stops instead.
     */
    private function assertApiAccessReleased(User $user, \Throwable $failure): void
    {
        if ($this->apiAccess->entryFor($user) === null) {
            return;
        }

        throw new \RuntimeException(
            "Could not remove the Engine API access of '{$user->username}', so the project was not deleted: ".$failure->getMessage(),
            0,
            $failure,
        );
    }

    /**
     * Another token scoped to this name would reach the next project created
     * under it; it keeps its scope, without this project.
     */
    private function forgetTokenScopes(string $username): void
    {
        foreach (PersonalAccessToken::query()->cursor() as $token) {
            $abilities = TokenAbilities::of($token);
            $projects = $abilities->projects() ?? [];
            if (in_array($username, $projects, true)) {
                $token->forceFill(['abilities' => $abilities->withProjects(array_values(array_diff($projects, [$username])))])->save();
            }
        }
    }

    /**
     * @param  bool  $lockHeldElsewhere  the account's deploy lock is held, so not by this delete
     *
     * @throws ProjectBusyException
     */
    private function assertIdle(User $user, bool $lockHeldElsewhere = false): void
    {
        $this->assertNoTaskRunning($user, $lockHeldElsewhere);
        $this->assertNoCancelledTaskStopping($user, $lockHeldElsewhere);
        $this->assertNoPushRunning($user);
    }

    /**
     * @throws ProjectBusyException
     */
    private function assertNoTaskRunning(User $user, bool $lockHeldElsewhere): void
    {
        $running = $this->tasksOf($user)
            ->where('status', Task::STATUS_RUNNING)
            ->orderBy('id')
            ->get()
            ->first(static fn (Task $task): bool => self::stillWorking($task, $lockHeldElsewhere));
        if ($running !== null) {
            throw ProjectBusyException::taskRunning($user->username, $running);
        }
    }

    /**
     * A cancel marks the task at once; its job may still be at work.
     *
     * @throws ProjectBusyException
     */
    private function assertNoCancelledTaskStopping(User $user, bool $lockHeldElsewhere): void
    {
        $cancelled = $this->tasksOf($user)
            ->where('status', Task::STATUS_CANCELLED)
            ->whereNotNull('job_id')
            ->where('cancelled_at', '>=', now()->subSeconds(self::STOPPING_WINDOW_SECONDS))
            ->orderBy('id')
            ->get();
        foreach ($cancelled as $task) {
            if (self::stillWorking($task, $lockHeldElsewhere) && ($this->inQueue)($task) === true) {
                throw ProjectBusyException::taskStopping($user->username, $task);
            }
        }
    }

    /**
     * A push has no task: its target carries the flag Projects::assertIdle() reads.
     *
     * @throws ProjectBusyException
     */
    private function assertNoPushRunning(User $user): void
    {
        foreach ([$user, $user->liveUser, $user->stagingUser] as $side) {
            if ($side !== null && ($side->asyncStatus()['push'] ?? null) === 'running') {
                throw ProjectBusyException::pushRunning($user->username);
            }
        }
    }

    /**
     * A task whose worker died is stopped, though its row may still say running and its queue
     * row stays reserved for a day. Unless the lock is held elsewhere: a build that worker
     * started may be what holds it.
     */
    private static function stillWorking(Task $task, bool $lockHeldElsewhere): bool
    {
        return $lockHeldElsewhere || ! TaskReconciler::workerGone($task);
    }

    /**
     * A task that cannot be cancelled was taken by a worker since the idle check, so it is re-run.
     *
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
            if (! $this->canceller->cancelQueued($task)) {
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
