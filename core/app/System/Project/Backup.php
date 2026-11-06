<?php

namespace App\System\Project;

use App\Exceptions\DeployAlreadyRunningException;
use App\Integrations\Storage\BackupStorage;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\BackupItem;
use App\Support\JobDeadline;
use App\System\Project as UserProject;
use App\System\Project\Backup\AppStack;
use App\System\Project\Backup\BackupCapture;
use App\System\Project\Backup\BackupRestore;
use App\System\Project\Backup\BackupTransfer;
use App\System\Project\Backup\ItemFilter;
use App\System\Project\Backup\ProjectContent;
use App\System\Project\Backup\StagingDirectory;
use App\System\Project\Backup\StoppedStack;
use Throwable;

/** Creates, restores and deletes one backup of a project, under the deploy lock. */
class Backup
{
    private JobDeadline $deadline;

    private ?ProjectContent $content = null;

    private ?AppStack $stack = null;

    public function __construct(
        private UserProject $project,
        private BackupRecord $record,
        ?JobDeadline $deadline = null,
    ) {
        $this->deadline = $deadline ?? JobDeadline::startingNow();

        if ($this->record->user_id !== $this->project->model()->id) {
            throw new \RuntimeException('Backup not found');
        }
    }

    public function model(): BackupRecord
    {
        return $this->record;
    }

    /**
     * Capture ~/project, the named volumes and the databases with the stack stopped, then upload them.
     * The deploy lock keeps a rebuild, a push or a git action off the stopped stack; a running deploy refuses the backup.
     */
    public function run(): void
    {
        $record = $this->record;
        $container = $this->requireContainer();
        $storage = $this->resolveStorage($container);

        $lock = DeployLock::acquireFor($this->project->username(), DeployLock::HOLDER_BACKUP);

        try {
            $this->assertRoomForBackup();

            $record->saveBackupStatus('running');

            if ($this->project->runsInDind()) {
                $this->stoppedStack()->run(
                    $record->id,
                    fn (string $stagingDir) => $this->captureAndUpload($stagingDir, $container, $storage),
                );
            } else {
                $this->captureClassicAndUpload($container, $storage);
            }

            $record->saveBackupStatus('completed');
        } catch (Throwable $e) {
            $this->markBackupFailed($container, $storage, $e);
        } finally {
            $lock->release();
        }
    }

    /** run() for a caller that prepared the row itself: a backup the deploy lock refuses never started, so its row goes. */
    public function runOrDiscard(): void
    {
        try {
            $this->run();
        } catch (DeployAlreadyRunningException $e) {
            $this->record->delete();

            throw $e;
        }
    }

    /** Holds the deploy lock as run() does, so no rebuild lands between the two moves of ProjectFiles::swap(). */
    public function restore(array $only = [], array $exclude = [], bool $deleteExistingFiles = true): void
    {
        $this->record->loadMissing(['container', 'items']);
        if ($this->record->backupStatus() !== 'completed' || $this->record->items->isEmpty()) {
            throw new \RuntimeException('Backup is not complete');
        }

        if (ItemFilter::isNonEmpty($only) && ItemFilter::isNonEmpty($exclude)) {
            throw new \RuntimeException('Cannot combine include and exclude');
        }

        $lock = DeployLock::acquireFor($this->project->username(), DeployLock::HOLDER_RESTORE);

        try {
            $this->record->restore_details = [
                'only' => $only,
                'exclude' => $exclude,
                'delete_existing_files' => $deleteExistingFiles,
            ];
            $this->record->saveRestoreStatus('running');

            if ($this->project->runsInDind()) {
                $this->restorer()->restoreDind($only, $exclude);
            } else {
                $this->restorer()->restoreClassic($only, $exclude, $deleteExistingFiles);
            }
        } finally {
            $lock->release();
        }
    }

    public function delete(): void
    {
        $record = $this->record;
        $container = $this->requireContainer();

        $storage = $this->resolveStorage($container);
        $record->saveDeleteStatus('running');

        try {
            $storage->deletePrefix(BackupTransfer::prefix($container, $record->username, $record->id));
        } catch (Throwable $e) {
            $record->saveDeleteStatus('failed', $e->getMessage());

            return;
        }

        $record->delete();
    }

    protected function resolveStorage(BackupContainer $container): BackupStorage
    {
        return $container->storage();
    }

    protected function content(): ProjectContent
    {
        return $this->content ??= ProjectContent::of($this->project, $this->deadline);
    }

    protected function stack(): AppStack
    {
        return $this->stack ??= new AppStack($this->project);
    }

    private function assertRoomForBackup(): void
    {
        $this->project->system()->filesystem()->assertFreeSpace(
            $this->capture()->requiredBytes(),
        );
    }

    /** Called with the stack stopped; the upload runs once it is started again. */
    private function captureAndUpload(string $stagingDir, BackupContainer $container, BackupStorage $storage): void
    {
        $this->capture()->write($stagingDir);
        $this->stoppedStack()->tryStart($this->record->id);
        $this->transfer()->upload($container, $storage, $this->capture()->manifest($stagingDir));
    }

    private function captureClassicAndUpload(BackupContainer $container, BackupStorage $storage): void
    {
        $staging = $this->staging();
        $stagingDir = $staging->path($this->record->username, $this->record->id);
        $staging->ensure($stagingDir);

        try {
            $this->capture()->write($stagingDir);
            $this->transfer()->upload($container, $storage, $this->capture()->manifest($stagingDir));
        } finally {
            $staging->clean($stagingDir);
        }
    }

    private function requireContainer(): BackupContainer
    {
        $this->record->loadMissing('container');
        $container = $this->record->container;
        if ($container === null) {
            throw new \RuntimeException('Backup container not found for record '.$this->record->id);
        }

        return $container;
    }

    private function markBackupFailed(
        BackupContainer $container,
        BackupStorage $storage,
        Throwable $e,
    ): void {
        $record = $this->record;
        $cleanupError = null;

        try {
            $storage->deletePrefix(BackupTransfer::prefix($container, $record->username, $record->id));
            BackupItem::query()->where('backup_id', $record->id)->delete();
        } catch (Throwable $cleanup) {
            $cleanupError = $cleanup->getMessage();
        }

        $record->setBackupStatus('failed');
        $record->error = $cleanupError === null
            ? $e->getMessage()
            : $e->getMessage().'; cleanup failed: '.$cleanupError;
        $record->save();
    }

    private function capture(): BackupCapture
    {
        return new BackupCapture($this->project, $this->record, $this->deadline, $this->content());
    }

    private function transfer(): BackupTransfer
    {
        return new BackupTransfer($this->record, $this->deadline);
    }

    private function staging(): StagingDirectory
    {
        return new StagingDirectory($this->project);
    }

    private function stoppedStack(): StoppedStack
    {
        return new StoppedStack($this->project, $this->stack(), $this->staging());
    }

    private function restorer(): BackupRestore
    {
        return new BackupRestore(
            $this->project,
            $this->record,
            $this->deadline,
            $this->content(),
            $this->stack(),
            fn (): BackupStorage => $this->resolveStorage($this->requireContainer()),
        );
    }
}
