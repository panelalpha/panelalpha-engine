<?php

namespace App\Lib\Project;

use App\Auth\TokenAbilities;
use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\ProjectBusyException;
use App\Jobs\CopyProject;
use App\Jobs\CreateStaging;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Project\PublicRouting\PublicRoutingGrants;
use App\Lib\Task\ProcessTreeKiller;
use App\Lib\Task\TaskCanceller;
use App\Lib\Task\TaskReconciler;
use App\Models\PersonalAccessToken;
use App\Models\Task;
use App\Models\User;
use App\Support\JobBudget;
use App\System;
use App\System\Projects;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Deleting a project never overlaps a job on its account: queued jobs are cancelled first, a running one refuses the
 * delete, and the deploy lock held throughout refuses a deploy, rebuild or push that arrives meanwhile.
 */
class ProjectDeleter
{
    /** The longest a job may run (its $timeout); a cancelled one older than that is gone. */
    private const STOPPING_WINDOW_SECONDS = JobBudget::SECONDS;

    private TaskCanceller $canceller;

    private ApiAccess $apiAccess;

    private PublicRoutingGrants $publicRouting;

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
        ?PublicRoutingGrants $publicRouting = null,
    ) {
        $this->canceller = $canceller ?? new TaskCanceller(new ProcessTreeKiller(new System));
        $this->inQueue = $inQueue ?? TaskReconciler::queueCheck();
        $this->apiAccess = $apiAccess ?? new ApiAccess;
        $this->publicRouting = $publicRouting ?? new PublicRoutingGrants;
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
            $this->removeFromApiAccessScopes($user);
            $this->publicRouting->forget($user->username);
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
     * The account's Engine API allowance and token go with it. Nothing is applied: the lock this delete holds is the one
     * an apply would need, and the next firewall apply drops the rule anyway.
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
     * An allowance still naming this project would hand the name to the token
     * its next rotation mints; when it cannot be taken out, the delete stops.
     */
    private function removeFromApiAccessScopes(User $user): void
    {
        try {
            $this->apiAccess->removeFromScopes($user->username);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Could not remove '{$user->username}' from the Engine API scope of other projects, so the project was not deleted: ".$e->getMessage(),
                0,
                $e,
            );
        }
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
        Projects::assertNoPushInPair($user);
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
     * A task whose worker died is stopped, though its row may say running and its queue row stays reserved for a day;
     * unless the lock is held elsewhere, since a build that worker started may be what holds it.
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
        $queued = $this->tasksOf($user)
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
     * The project's own tasks, and a staging copy or project copy reading from
     * it: a copy's task is the destination's, the source named in its details.
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
                })
                ->orWhere(static function (Builder $copy) use ($user): void {
                    $copy->where('job_type', CopyProject::class)
                        ->where('details->from', $user->username);
                });
        });
    }
}
