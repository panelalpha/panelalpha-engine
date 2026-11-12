<?php

namespace App\Lib\Git;

use App\Exceptions\ProjectBusyException;
use App\Jobs\DeployProject;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Project\ProjectRebuild;
use App\Models\User;
use App\System\Project as ProjectAggregate;
use App\System\Project\Git\Exception as GitException;

/**
 * Refuses a change to a project's checkout while the project is busy: being created, deployed, backed up,
 * restored or deleted. Each refusal is a 409 {@see GitException}.
 */
final class CheckoutChangeGuard
{
    /**
     * A change while the create runs would reach an account that may not exist yet.
     * One while a queued rebuild or archive deploy runs would change the files it is building from.
     *
     * @throws GitException
     */
    public static function refuseWhileCreating(User $user): void
    {
        $task = ProjectRebuild::pendingTask($user->username);
        if ($task === null) {
            return;
        }

        throw new GitException(
            $task->job_type === DeployProject::class
                ? "Project '{$user->username}' is still being created; try again when its deploy has finished."
                : "A deploy of project '{$user->username}' is queued or running (task {$task->id}); try again when it has finished.",
            409,
            taskId: $task->id,
        );
    }

    /**
     * Refuse a change while the project lock is held: a hook delivery queues no task, and changing the checkout
     * under it would settle its tree mid-build. A `$logger` is the queued path's own deploy, which holds the lock.
     *
     * @throws GitException
     */
    public static function refuseWhileTheLockIsHeld(ProjectAggregate $project, ?DeployLogger $logger): void
    {
        if ($logger !== null || ! DeployLogger::isLockedFor($project->username())) {
            return;
        }

        throw self::lockHeldRefusal($project->username());
    }

    /** What holds the lock, decided as {@see ProjectRebuild::assertIdle()} does: a backup or restore task, else a deploy. */
    private static function lockHeldRefusal(string $username): GitException
    {
        $backup = ProjectRebuild::pendingBackupTask($username);
        if ($backup !== null) {
            return new GitException(ProjectBusyException::taskRunning($username, $backup)->getMessage(), 409, taskId: $backup->id);
        }

        return self::holderRefusal($username, DeployLock::heldBy($username)) ?? self::runningDeployRefusal($username);
    }

    /** The refusal a lock held by a CLI backup, restore or delete gets, or null for any other holder. */
    private static function holderRefusal(string $username, ?string $holder): ?GitException
    {
        if ($holder === DeployLock::HOLDER_BACKUP || $holder === DeployLock::HOLDER_RESTORE) {
            return new GitException(
                "A {$holder} of project '{$username}' is running; try again when it has finished.",
                409,
            );
        }
        if ($holder === DeployLock::HOLDER_DELETE) {
            return new GitException(
                "Project '{$username}' is being deleted; try again when it has finished.",
                409,
            );
        }

        return null;
    }

    private static function runningDeployRefusal(string $username): GitException
    {
        $task = ProjectRebuild::pendingTask($username);

        return new GitException(
            "A deploy of project '{$username}' is running".($task !== null ? " (task {$task->id})" : '')
                .'; try again when it has finished.',
            409,
            taskId: $task?->id,
        );
    }
}
