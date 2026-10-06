<?php

namespace App\Exceptions;

use App\Jobs\CreateBackup;
use App\Jobs\CreateStaging;
use App\Jobs\DeleteBackup;
use App\Jobs\DeployProject;
use App\Jobs\RebuildProject;
use App\Jobs\RestoreBackup;
use App\Lib\Project\ProjectRebuild;
use App\Models\Task;

/**
 * A delete refused because something is working on the project's account.
 * HTTP answers 409 with the message; the CLI prints {@see plainMessage()},
 * which says the same without naming an endpoint.
 */
class ProjectBusyException extends \RuntimeException
{
    private function __construct(
        private readonly string $state,
        string $hint,
        private readonly string $plainHint,
    ) {
        parent::__construct("{$state} {$hint}", 409);
    }

    public static function taskRunning(string $project, Task $task): self
    {
        return new self(
            "Project '{$project}' is busy: " . self::kind($task) . " task {$task->id} is running.",
            "Cancel it (POST /tasks/{$task->id}/cancel) or wait for it to finish.",
            "Cancel task {$task->id} or wait for it to finish.",
        );
    }

    /** Cancelled, but its job has not let go of the account yet. */
    public static function taskStopping(string $project, Task $task): self
    {
        return new self(
            "Project '{$project}' is busy: " . self::kind($task) . " task {$task->id} was cancelled and is still stopping.",
            'Wait for it to finish.',
            'Wait for it to finish.',
        );
    }

    /** The deploy lock is held by a deploy with no task: a rebuild, a git pull, a push to deploy. */
    public static function deployRunning(string $project): self
    {
        return new self("Project '{$project}' is busy: a deploy is running.", 'Wait for it to finish.', 'Wait for it to finish.');
    }

    public static function pushRunning(string $project): self
    {
        return new self("Project '{$project}' is busy: a push is running.", 'Wait for it to finish.', 'Wait for it to finish.');
    }

    public function plainMessage(): string
    {
        return "{$this->state} {$this->plainHint}";
    }

    private static function kind(Task $task): string
    {
        return match ($task->job_type) {
            DeployProject::class => 'deploy',
            RebuildProject::class => match ($task->details['action'] ?? null) {
                ProjectRebuild::DEPLOY_ARCHIVE => 'archive deploy',
                ProjectRebuild::GIT_PULL => 'git pull',
                ProjectRebuild::CHANGE_BRANCH => 'branch change',
                ProjectRebuild::REVERT => 'git revert',
                default => 'rebuild',
            },
            CreateStaging::class => 'staging copy',
            CreateBackup::class => 'backup',
            RestoreBackup::class => 'restore',
            DeleteBackup::class => 'backup delete',
            default => class_basename((string) $task->job_type),
        };
    }
}
