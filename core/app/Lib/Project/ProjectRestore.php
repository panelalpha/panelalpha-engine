<?php

namespace App\Lib\Project;

use App\Exceptions\ProblemException;
use App\Jobs\RestoreBackupInto;
use App\Models\Backup as BackupRecord;
use App\Models\Task;
use App\Models\User;
use App\System;
use App\System\Project\Dind\AppDatabase;
use App\System\Projects;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * A backup restored into a project of its own: the new account is provisioned,
 * the backup's items are restored into it, and the restored application is
 * started on the new project's domain. The project that owns the backup, and
 * the backup itself, are left as they were.
 *
 * Container projects only. A PHP hosting backup also carries an application
 * configuration that names the source project's databases, and the restore
 * writes only the databases it maps, so such a copy would answer from the
 * source's data.
 */
class ProjectRestore
{
    public const string ACTION = 'restore_into';

    private ?User $target = null;

    public function __construct(
        private readonly System $system,
        private readonly ProjectCreator $creator,
    ) {}

    /**
     * @throws ProblemException
     */
    private static function assertRestorable(User $source, BackupRecord $record): void
    {
        if (! $source->project()->runsInDind()) {
            throw ProblemException::one(
                'backup',
                'restore_into_unsupported',
                'Only backups of container projects can be restored into a new project.'
            );
        }

        $record->loadMissing('items');
        if ($record->backupStatus() !== 'completed' || $record->items->isEmpty()) {
            throw ProblemException::conflict('backup', 'backup_not_completed', 'Backup is not complete');
        }
    }

    /** The backup restore records its own failure on the new project rather than throwing it. */
    private static function assertRestored(User $target): void
    {
        if (($target->asyncStatus()['restore'] ?? null) !== 'failed') {
            return;
        }

        $error = $target->getDetails()['error'] ?? null;

        throw new RuntimeException(is_string($error) && $error !== '' ? $error : 'Restore failed.');
    }

    /** The new project, created before the task so the caller can poll it. */
    public function queue(User $source, BackupRecord $record, NewProjectInput $input): Task
    {
        $target = $this->createTarget($source, $record, $input);

        $task = Task::start(
            jobType: RestoreBackupInto::class,
            queue: 'default',
            username: $target->username,
            details: [
                'source' => $source->username,
                'target' => $target->username,
                'backup_id' => $record->id,
                'action' => self::ACTION,
            ],
        );
        RestoreBackupInto::dispatch($record->id, $target->username)->attachTask($task);

        return $task;
    }

    /** The same run for a caller that waits for it, like the CLI. */
    public function restoreNow(User $source, BackupRecord $record, NewProjectInput $input): User
    {
        $target = $this->createTarget($source, $record, $input);
        $this->restore($source, $record, $target);

        return $target->fresh() ?? $target;
    }

    public function target(): User
    {
        if ($this->target === null) {
            throw new RuntimeException('No project was created for this restore.');
        }

        return $this->target;
    }

    public function restore(User $source, BackupRecord $record, User $target): void
    {
        try {
            $this->system->project($target)->provision();
            $this->provisionApplicationDatabase($source, $target);
            $this->system->project($source)->backup($record)->restoreInto($this->system->project($target));
            self::assertRestored($target);
            $this->start($source, $target);
            $this->publish($target);
            $this->markRestored($target);
        } catch (Throwable $e) {
            $target->markRestoreFailed($e->getMessage());

            throw $e;
        }
    }

    /**
     * @throws ValidationException
     * @throws ProblemException
     */
    private function createTarget(User $source, BackupRecord $record, NewProjectInput $input): User
    {
        self::assertRestorable($source, $record);
        Projects::assertDatabaseNamesFit($source, $input->params['username'].'_');

        $target = $this->creator->provisionForDeploy($input);
        $target->applyDeploySnapshotFrom($source);
        $target->status = 'pending';
        $target->mergeAsyncStatus(['restore' => 'pending']);
        $target->save();

        return $this->target = $target;
    }

    /** The copy's own application database, ready before the backup's dump is loaded into it. */
    private function provisionApplicationDatabase(User $source, User $target): void
    {
        if (! AppDatabase::isOwnedBy($source)) {
            return;
        }

        AppDatabase::provision($target, $this->system->mysql());
    }

    /**
     * Nothing deployed this account, so the restored files are what starts it; they arrive owned by
     * the source account and are handed to this one first, as a clone does.
     */
    private function start(User $source, User $target): void
    {
        $project = $this->system->project($target);
        $project->fixPermissions();
        $project->repointCopiedRunFile($source);

        if (! $project->hasUserApp()) {
            return;
        }

        $result = $project->startUserApp();
        if (($result['exit_code'] ?? 1) !== 0) {
            $output = trim($result['stderr'].' '.$result['stdout']);

            throw new RuntimeException(
                $output !== '' ? $output : 'Failed to start the restored application.'
            );
        }
    }

    /** The new project answers on its own domain: a detected port, then the domain's rules and vhost. */
    private function publish(User $target): void
    {
        $domain = $target->getMainDomain();
        if ($domain === null) {
            throw new RuntimeException("Project '{$target->username}' has no main domain.");
        }

        $project = $this->system->project($target);
        $project->syncGeneratedProxyRules();
        $project->domain($domain)->create();
    }

    private function markRestored(User $target): void
    {
        $target->markDeploySucceeded();
        $target->status = 'active';
        $target->mergeAsyncStatus(['restore' => 'completed']);
        $target->save();
    }
}
