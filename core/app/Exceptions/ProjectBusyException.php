<?php

namespace App\Exceptions;

use App\Jobs\ApplyEnvVars;
use App\Jobs\CopyProject;
use App\Jobs\CreateBackup;
use App\Jobs\CreateStaging;
use App\Jobs\DeleteBackup;
use App\Jobs\DeployProject;
use App\Jobs\PushState;
use App\Jobs\RebuildProject;
use App\Jobs\RenameMysqlDatabase;
use App\Jobs\RestoreBackup;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Project\ApiAccess;
use App\Lib\Project\ProjectRebuild;
use App\Models\Task;

/**
 * A delete refused because something is working on the project's account; answered 409, with the task to follow
 * or cancel when there is one.
 */
class ProjectBusyException extends \RuntimeException
{
    public const CODE = 'project_busy';

    /** Busy with a task that can be followed and cancelled. */
    public const TASK = 'project_busy_task';

    private const WAIT = 'Wait for it to finish.';

    private function __construct(string $state, string $hint, public readonly ?Task $task = null)
    {
        parent::__construct("{$state} {$hint}", 409);
    }

    /**
     * @return array{code: string, task_id?: int}
     */
    public function problem(): array
    {
        return $this->task === null ? ['code' => self::CODE] : ['code' => self::TASK, 'task_id' => $this->task->id];
    }

    public static function taskRunning(string $project, Task $task): self
    {
        $state = "Project '{$project}' is busy: ".self::describe($task).'.';

        if (! self::canBeCancelled($task)) {
            return new self($state, self::WAIT);
        }

        return new self($state, "Cancel task {$task->id} or wait for it to finish.", $task);
    }

    /** Cancelled, but its job has not let go of the account yet. */
    public static function taskStopping(string $project, Task $task): self
    {
        return new self(
            "Project '{$project}' is busy: ".self::kind($task)." task {$task->id} was cancelled and is still stopping.",
            self::WAIT,
        );
    }

    /** The deploy lock is held by something with no task: a deploy started elsewhere, a delete, a backup or a restore. */
    public static function lockHeld(string $project): self
    {
        $holder = DeployLock::heldBy($project);

        return match ($holder) {
            DeployLock::HOLDER_BACKUP, DeployLock::HOLDER_RESTORE => new self(
                "Project '{$project}' is busy: a {$holder} is running.",
                self::WAIT,
            ),
            DeployLock::HOLDER_DELETE => new self("Project '{$project}' is being deleted.", self::WAIT),
            default => new self("Project '{$project}' is busy: a deploy is running.", self::WAIT),
        };
    }

    public static function pushRunning(string $project): self
    {
        return new self("Project '{$project}' is busy: a push is running.", self::WAIT);
    }

    public static function stagingRunning(string $project): self
    {
        return new self("Project '{$project}' is busy: a staging copy is running.", self::WAIT);
    }

    /** The task as a refusal names it: its kind, its id and whether it is queued or running. */
    public static function describe(Task $task): string
    {
        return self::kind($task)." task {$task->id} is ".self::progress($task);
    }

    /**
     * TaskCanceller refuses a running job that records no pid, so a cancel must not be offered for it.
     */
    private static function canBeCancelled(Task $task): bool
    {
        return ! ($task->status === Task::STATUS_RUNNING && ! $task->isDeploy() && $task->pid === null);
    }

    private static function progress(Task $task): string
    {
        return $task->status === Task::STATUS_QUEUED ? 'queued' : 'running';
    }

    private static function kind(Task $task): string
    {
        return match ($task->job_type) {
            DeployProject::class => 'deploy',
            RebuildProject::class => match ($task->details['action'] ?? null) {
                ProjectRebuild::DEPLOY_ARCHIVE => 'archive deploy',
                ProjectRebuild::ATTACH_SOURCE => 'source attach',
                ProjectRebuild::GIT_PULL => 'git pull',
                ProjectRebuild::CHANGE_BRANCH => 'branch change',
                ProjectRebuild::REVERT => 'git revert',
                default => 'rebuild',
            },
            CreateStaging::class => 'staging copy',
            CopyProject::class => 'project copy',
            PushState::class => 'push',
            RenameMysqlDatabase::class => 'database rename',
            CreateBackup::class => 'backup',
            ApplyEnvVars::class => match ($task->details['action'] ?? null) {
                ApiAccess::TASK_ACTION => 'API access apply',
                default => 'environment change',
            },
            RestoreBackup::class => 'restore',
            DeleteBackup::class => 'backup delete',
            default => class_basename((string) $task->job_type),
        };
    }
}
