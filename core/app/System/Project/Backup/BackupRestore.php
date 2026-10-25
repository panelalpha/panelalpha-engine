<?php

namespace App\System\Project\Backup;

use App\Integrations\Storage\BackupStorage;
use App\Models\Backup as BackupRecord;
use App\Models\BackupItem;
use App\Support\JobDeadline;
use App\System\Project as UserProject;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\WpConfigRestriction;
use Illuminate\Support\Collection;
use Throwable;

/** Puts a completed backup's items back; the first failure undoes every item restored before it. */
final class BackupRestore
{
    private const string PROJECT_INCOMING = 'project.incoming';

    private const string PROJECT_PRE_RESTORE = 'project.pre-restore';

    /** Set by a restore rollback step that failed; a stopped stack then stays stopped. */
    private bool $rollbackStepFailed = false;

    private ?DatabaseRestore $databaseRestore = null;

    private StagingDirectory $staging;

    private BackupTransfer $transfer;

    /**
     * @param  \Closure(): BackupStorage  $storage
     */
    public function __construct(
        private UserProject $project,
        private BackupRecord $record,
        JobDeadline $deadline,
        private ProjectContent $content,
        private AppStack $stack,
        private \Closure $storage,
        private ?RestoreCopy $copy = null,
    ) {
        $this->staging = new StagingDirectory($project);
        $this->transfer = new BackupTransfer($record, $deadline);
    }

    public function restoreDind(array $only, array $exclude): void
    {
        $record = $this->record;
        $this->rollbackStepFailed = false;

        try {
            $storage = ($this->storage)();
            $selectedItems = $this->restorableItems($only, $exclude);

            $this->project->system()->filesystem()->assertFreeSpace(
                $this->sumSelectedBytes($selectedItems),
            );

            $this->withStackStopped(
                $record->id,
                fn (string $stagingDir) => $this->restoreWhileStopped($selectedItems, $storage, $stagingDir),
            );

            $this->saveStatus('completed');
        } catch (Throwable $e) {
            $this->saveStatus('failed', $e->getMessage());
        }
    }

    /** Classic restore: no stack to stop, the databases come back through MariaDB and the home is swapped or overwritten. */
    public function restoreClassic(array $only, array $exclude, bool $deleteExistingFiles): void
    {
        $record = $this->record;
        $this->rollbackStepFailed = false;
        $stagingDir = $this->staging->path($record->username, $record->id);

        try {
            $storage = ($this->storage)();
            $selectedItems = $this->restorableItems($only, $exclude);

            $this->project->system()->filesystem()->assertFreeSpace(
                $this->sumSelectedBytes($selectedItems),
            );
            $this->staging->ensure($stagingDir);

            $stagedPaths = $this->transfer->download($selectedItems, $storage, $stagingDir);
            $this->restoreClassicItems($selectedItems, $stagedPaths, $stagingDir, $deleteExistingFiles);

            $this->saveStatus('completed');
        } catch (Throwable $e) {
            $this->saveStatus('failed', $e->getMessage());
        } finally {
            $this->staging->clean($stagingDir);
        }
    }

    /**
     * Databases go first, each behind its own pre-restore dump, so a failed import or a failed
     * file restore puts back every database restored so far before the old home is discarded.
     *
     * @param  Collection<int, BackupItem>  $selectedItems
     * @param  array<int, string>  $stagedPaths
     */
    private function restoreClassicItems(
        Collection $selectedItems,
        array $stagedPaths,
        string $stagingDir,
        bool $deleteExistingFiles,
    ): void {
        $databases = $this->startDatabaseRestore($stagingDir);

        try {
            $this->restoreClassicDatabases($selectedItems, $stagedPaths, $databases);

            $filesItem = $this->findItemByType($selectedItems, 'files');
            if ($filesItem !== null) {
                $this->restoreClassicFiles($stagedPaths[$filesItem->id], $deleteExistingFiles);
            }
        } catch (Throwable $e) {
            $databases->rollBack();

            throw $this->restoreFailure($e);
        }
    }

    private function restoreClassicFiles(string $archivePath, bool $deleteExistingFiles): void
    {
        if (! $deleteExistingFiles) {
            $this->mergeClassicFiles($archivePath);

            return;
        }

        $home = $this->content->home;
        $incoming = '.restore-'.$this->record->id;
        $aside = '.pre-restore-'.$this->record->id;
        $skip = [$incoming, $aside];

        $home->removeEntry($incoming);
        $home->removeEmptyAside($aside);
        $home->extract($archivePath, $home->path($incoming));

        try {
            $home->swap($incoming, $aside, $skip);
        } catch (Throwable $e) {
            $this->attemptRollback(fn () => $home->rollback($incoming, $aside));

            throw $e;
        }

        $home->discardAside($aside);
        $this->handHomeToAccount();
    }

    /** The scratch directory goes whether the merge lands or not; the live home is only ever added to. */
    private function mergeClassicFiles(string $archivePath): void
    {
        $home = $this->content->home;
        $incoming = '.restore-'.$this->record->id;

        $home->removeEntry($incoming);
        try {
            $home->merge($archivePath, $incoming);
        } finally {
            $home->removeEntry($incoming);
        }

        $this->handHomeToAccount();
    }

    /**
     * Hands the restored home back and restricts its wp-config.php files again, since a backup restores the mode
     * it recorded. An account without its own Linux user is skipped.
     */
    private function handHomeToAccount(): void
    {
        $this->project->fixPermissions();
        $runtime = $this->project->runtime();
        $account = $this->project->model();
        if ($runtime instanceof PhpHosting && $account->getUid() !== null && $account->getGid() !== null) {
            (new WpConfigRestriction($runtime))->apply();
        }
    }

    /**
     * @param  Collection<int, BackupItem>  $selectedItems
     * @param  array<int, string>  $stagedPaths
     */
    private function restoreClassicDatabases(Collection $selectedItems, array $stagedPaths, DatabaseRestore $databases): void
    {
        foreach ($selectedItems as $item) {
            if (($item->details['type'] ?? null) !== 'database') {
                continue;
            }

            $name = (string) ($item->details['name'] ?? '');
            $this->content->databases->ensureRow($name);
            $databases->restore($name, $stagedPaths[$item->id]);
        }
    }

    /**
     * @return Collection<int, BackupItem>
     */
    private function restorableItems(array $only, array $exclude): Collection
    {
        $selectedItems = ItemFilter::select($this->record->items, $only, $exclude);
        if (
            $selectedItems->isEmpty()
            && (ItemFilter::isNonEmpty($only) || ItemFilter::isNonEmpty($exclude))
        ) {
            throw new \RuntimeException('No backup items match the restore filter');
        }

        return $selectedItems;
    }

    /**
     * @param  Collection<int, BackupItem>  $selectedItems
     */
    private function restoreWhileStopped(Collection $selectedItems, BackupStorage $storage, string $stagingDir): void
    {
        $stagedPaths = $this->transfer->download($selectedItems, $storage, $stagingDir);

        $filesItem = $this->findItemByType($selectedItems, 'files');
        if ($filesItem !== null) {
            $this->swapInProjectArchive($stagedPaths[$filesItem->id]);
        }

        $this->restoreComponents($selectedItems, $stagedPaths, $stagingDir, $filesItem !== null);

        if ($filesItem !== null) {
            $this->content->files->discardAside(self::PROJECT_PRE_RESTORE);
        }

        if ($this->copy === null) {
            $this->stack->start();
        }
    }

    private function swapInProjectArchive(string $archivePath): void
    {
        $this->content->files->extract($archivePath, self::PROJECT_INCOMING);

        try {
            $this->content->files->swap(self::PROJECT_INCOMING, self::PROJECT_PRE_RESTORE);
        } catch (Throwable $e) {
            $this->rollBackProjectFiles('');

            throw new \RuntimeException('Project swap failed: '.$e->getMessage());
        }
    }

    /**
     * Restore the selected volumes and databases; on the first failure undo every component restored so far.
     *
     * @param  Collection<int, BackupItem>  $selectedItems
     * @param  array<int, string>  $stagedPaths
     */
    private function restoreComponents(
        Collection $selectedItems,
        array $stagedPaths,
        string $stagingDir,
        bool $filesSwapped,
    ): void {
        $restoredVolumes = [];
        $databases = $this->startDatabaseRestore($stagingDir);

        try {
            foreach ($selectedItems as $item) {
                $type = $item->details['type'] ?? null;
                if ($type === 'volume') {
                    $restoredVolumes[] = $this->restoreVolumeItem($item, $stagedPaths[$item->id]);
                } elseif ($type === 'database') {
                    $this->restoreDatabaseItem($databases, (string) ($item->details['name'] ?? ''), $stagedPaths[$item->id]);
                }
            }
        } catch (Throwable $e) {
            $this->rollBackComponents($restoredVolumes, $databases, $filesSwapped);

            throw $this->restoreFailure($e);
        }
    }

    /** A copy loads each dump under the new project's prefix, with the source's names rewritten out of it. */
    private function restoreDatabaseItem(DatabaseRestore $databases, string $name, string $sqlGzPath): void
    {
        if ($this->copy === null) {
            $databases->restore($name, $sqlGzPath);

            return;
        }

        $target = $this->copy->databaseName($name);
        $this->content->databases->ensureRow($target);
        $databases->restoreCopy($name, $target, $sqlGzPath, $this->copy->definers());
    }

    private function restoreFailure(Throwable $e): \RuntimeException
    {
        if ($this->rollbackFailed()) {
            return new \RuntimeException(
                'Restore failed and rollback was incomplete: '.$e->getMessage(),
            );
        }

        return new \RuntimeException('Restore failed: '.$e->getMessage());
    }

    /** @return string the volume's docker name */
    private function restoreVolumeItem(BackupItem $item, string $archivePath): string
    {
        $volumes = $this->content->volumes;
        $dockerName = (string) ($item->details['docker_name'] ?? '');
        $volumes->ensure($dockerName);
        try {
            $volumes->restore($dockerName, $archivePath);
        } catch (Throwable $e) {
            $this->attemptRollback(fn () => $volumes->rollback($dockerName));
            throw $e;
        }

        return $dockerName;
    }

    /**
     * @param  list<string>  $restoredVolumes
     */
    private function rollBackComponents(array $restoredVolumes, DatabaseRestore $databases, bool $filesSwapped): void
    {
        foreach (array_reverse($restoredVolumes) as $dockerName) {
            $this->attemptRollback(fn () => $this->content->volumes->rollback($dockerName));
        }

        $databases->rollBack();

        if ($filesSwapped) {
            $this->rollBackProjectFiles(' after component restore error');
        }
    }

    /**
     * A copy's new account has never run, so there is no stack to stop or start again.
     *
     * @param  callable(string): void  $work
     */
    private function withStackStopped(int $backupId, callable $work): void
    {
        if ($this->copy === null) {
            (new StoppedStack($this->project, $this->stack, $this->staging))->run(
                $backupId,
                $work,
                fn (): bool => ! $this->rollbackFailed(),
            );

            return;
        }

        $stagingDir = $this->staging->path($this->project->username(), $backupId);
        try {
            $this->staging->ensure($stagingDir);
            $work($stagingDir);
        } finally {
            $this->staging->clean($stagingDir);
        }
    }

    /** A copy's progress belongs to the new project; the backup it was made from records nothing. */
    private function saveStatus(string $status, ?string $error = null): void
    {
        if ($this->copy !== null) {
            $this->copy->saveStatus($status, $error);

            return;
        }

        $this->record->saveRestoreStatus($status, $error);
    }

    private function startDatabaseRestore(string $stagingDir): DatabaseRestore
    {
        return $this->databaseRestore = new DatabaseRestore($this->content->databases, $stagingDir);
    }

    private function rollbackFailed(): bool
    {
        return $this->rollbackStepFailed || ($this->databaseRestore?->undoFailed() ?? false);
    }

    /** Run one rollback step; a failure is recorded, not thrown. */
    private function attemptRollback(callable $step): void
    {
        try {
            $step();
        } catch (Throwable) {
            $this->rollbackStepFailed = true;
        }
    }

    /** Put the pre-restore ~/project back; a failure is fatal, since the live content is then only in the aside. */
    private function rollBackProjectFiles(string $context): void
    {
        try {
            $this->content->files->rollback(self::PROJECT_PRE_RESTORE);
        } catch (Throwable $rollbackError) {
            $this->rollbackStepFailed = true;
            throw new \RuntimeException(
                'Project rollback failed'.$context.'; live content may be in '.self::PROJECT_PRE_RESTORE.': '.$rollbackError->getMessage(),
            );
        }
    }

    /**
     * @param  Collection<int, BackupItem>  $items
     */
    private function findItemByType(Collection $items, string $type): ?BackupItem
    {
        foreach ($items as $item) {
            if (($item->details['type'] ?? null) === $type) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, BackupItem>  $items
     */
    private function sumSelectedBytes(Collection $items): int
    {
        return (int) $items->sum('size_bytes');
    }
}
