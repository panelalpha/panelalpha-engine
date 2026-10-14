<?php

namespace App\System\Project;

use App\Integrations\Storage\BackupStorage;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\DeployHook\Coalescing;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\BackupItem;
use App\Models\MysqlDatabase;
use App\Support\JobBudget;
use App\System\Project as UserProject;
use App\System\Project\Dind;
use App\System\Project\Dind\ContainerOperations;
use App\System\Project\Dind\VolumeArchive;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Backup/restore/delete orchestrator for a Project.
 * Host ops live on Filesystem / Mysql / VolumeArchive / ContainerOperations;
 * ~/project tar/swap stays private here as restore protocol.
 */
class Backup
{
    private const string PROJECT_INCOMING = 'project.incoming';
    private const string PROJECT_PRE_RESTORE = 'project.pre-restore';
    private const string STAGING_ROOT = '/var/tmp/panelalpha-backup';

    /** Set by a restore rollback step that failed; a stopped stack then stays stopped. */
    private bool $rollbackFailed = false;

    public function __construct(
        private UserProject  $project,
        private BackupRecord $record,
    ) {
        if ($this->record->user_id !== $this->project->model()->id) {
            throw new \RuntimeException('Backup not found');
        }
    }

    public function model(): BackupRecord
    {
        return $this->record;
    }

    /**
     * Snapshot ~/project, the named volumes and the databases with the stack stopped, then upload them.
     * The deploy lock keeps a rebuild, a push or a git action off the stopped stack; a running deploy refuses the backup.
     */
    public function run(): void
    {
        $record = $this->record;
        $container = $this->requireContainer();
        $storage = $this->resolveStorage($container);

        $lock = DeployLock::acquireFor($this->project->username(), DeployLock::HOLDER_BACKUP);

        try {
            $this->project->system()->filesystem()->assertFreeSpace(
                $this->estimateRequiredBytes(),
            );

            $this->saveBackupStatus('running');

            if ($this->project->runsInDind()) {
                $this->withComposeStopped(
                    $record->id,
                    fn (string $stagingDir) => $this->snapshotAndUpload($stagingDir, $container, $storage),
                );
            } else {
                $this->snapshotClassicAndUpload($container, $storage);
            }

            $this->saveBackupStatus('completed');
        } catch (Throwable $e) {
            $this->markBackupFailed($container, $storage, $e);
        } finally {
            $this->releaseDeployLock($lock);
        }
    }

    /** Called with the stack stopped; the upload runs once it is started again. */
    private function snapshotAndUpload(string $stagingDir, BackupContainer $container, BackupStorage $storage): void
    {
        $this->writeSnapshot($stagingDir);
        $this->tryComposeStart($this->record->id);
        $this->uploadSnapshot($container, $storage, $stagingDir);
    }

    private function snapshotClassicAndUpload(BackupContainer $container, BackupStorage $storage): void
    {
        $stagingDir = $this->stagingDir($this->record->username, $this->record->id);
        $this->ensureDirectory($stagingDir);

        try {
            $this->writeSnapshot($stagingDir);
            $this->uploadSnapshot($container, $storage, $stagingDir);
        } finally {
            $this->cleanStagingDir($stagingDir);
        }
    }

    private function writeSnapshot(string $stagingDir): void
    {
        foreach ($this->snapshotManifest($stagingDir) as $file) {
            match ($file['type']) {
                'files' => ($file['scope'] ?? null) === 'home'
                    ? $this->tarHome($file['path'])
                    : $this->tarProject($file['path']),
                'volume' => $this->tarVolume($file['docker_name'], $file['path']),
                'database' => $this->dumpDatabase($file['name'], $file['path']),
            };
        }
    }

    /**
     * A push that arrived meanwhile was parked by Coalescing on the lock, and
     * there is no DeployLogger::finish() here to run it.
     */
    private function releaseDeployLock(DeployLock $lock): void
    {
        $lock->release();
        Coalescing::runPendingFor($this->project->username());
    }

    /** Holds the deploy lock as run() does, so no rebuild lands between the two moves of swapProject(). */
    public function restore(array $only = [], array $exclude = [], bool $deleteExistingFiles = true): void
    {
        $this->record->loadMissing(['container', 'items']);
        if ($this->record->backupStatus() !== 'completed' || $this->record->items->isEmpty()) {
            throw new \RuntimeException('Backup is not complete');
        }

        if ($this->isFilterNonEmpty($only) && $this->isFilterNonEmpty($exclude)) {
            throw new \RuntimeException('Cannot combine include and exclude');
        }

        $lock = DeployLock::acquireFor($this->project->username(), DeployLock::HOLDER_RESTORE);

        try {
            $this->record->restore_details = [
                'only' => $only,
                'exclude' => $exclude,
                'delete_existing_files' => $deleteExistingFiles,
            ];
            $this->saveRestoreStatus('running');

            if ($this->project->runsInDind()) {
                $this->restoreBackup($only, $exclude);
            } else {
                $this->restoreClassic($only, $exclude, $deleteExistingFiles);
            }
        } finally {
            $this->releaseDeployLock($lock);
        }
    }

    public function delete(): void
    {
        $record = $this->record;
        $container = $this->requireContainer();

        $storage = $this->resolveStorage($container);
        $this->saveDeleteStatus('running');

        try {
            $storage->deletePrefix($this->storagePrefix($container, $record->username, $record->id));
        } catch (Throwable $e) {
            $this->saveDeleteStatus('failed', $e->getMessage());

            return;
        }

        $record->delete();
    }

    private function requireContainer(): BackupContainer
    {
        $this->record->loadMissing('container');
        $container = $this->record->container;
        if ($container === null) {
            throw new \RuntimeException('Backup container not found for record ' . $this->record->id);
        }

        return $container;
    }

    private function saveBackupStatus(string $status, ?string $error = null): void
    {
        $this->record->setBackupStatus($status);
        $this->saveError($error);
    }

    private function saveRestoreStatus(string $status, ?string $error = null): void
    {
        $this->record->setRestoreStatus($status);
        $this->saveError($error);
    }

    private function saveDeleteStatus(string $status, ?string $error = null): void
    {
        $this->record->setDeleteStatus($status);
        $this->saveError($error);
    }

    private function saveError(?string $error): void
    {
        $this->record->error = $error;
        $this->record->save();
    }

    // --- restore mechanics ---

    private function restoreBackup(array $only, array $exclude): void
    {
        $record = $this->record;
        $storage = $this->resolveStorage($this->requireContainer());
        $this->rollbackFailed = false;

        try {
            $selectedItems = $this->restorableItems($only, $exclude);

            $this->project->system()->filesystem()->assertFreeSpace(
                $this->sumSelectedBytes($selectedItems),
            );

            $this->withComposeStopped(
                $record->id,
                fn (string $stagingDir) => $this->restoreWhileStopped($selectedItems, $storage, $stagingDir),
                fn (): bool => !$this->rollbackFailed,
            );

            $this->saveRestoreStatus('completed');
        } catch (Throwable $e) {
            $this->saveRestoreStatus('failed', $e->getMessage());
        }
    }

    /** Classic restore: no stack to stop, the databases come back through MariaDB and the home is swapped or overwritten. */
    private function restoreClassic(array $only, array $exclude, bool $deleteExistingFiles): void
    {
        $record = $this->record;
        $storage = $this->resolveStorage($this->requireContainer());
        $this->rollbackFailed = false;
        $stagingDir = $this->stagingDir($record->username, $record->id);

        try {
            $selectedItems = $this->restorableItems($only, $exclude);

            $this->project->system()->filesystem()->assertFreeSpace(
                $this->sumSelectedBytes($selectedItems),
            );
            $this->ensureDirectory($stagingDir);

            $stagedPaths = $this->downloadSelectedItems($selectedItems, $storage, $stagingDir);

            $filesItem = $this->findItemByType($selectedItems, 'files');
            if ($filesItem !== null) {
                $this->restoreClassicFiles($stagedPaths[$filesItem->id], $deleteExistingFiles);
            }

            $this->restoreClassicDatabases($selectedItems, $stagedPaths);

            $this->saveRestoreStatus('completed');
        } catch (Throwable $e) {
            $this->saveRestoreStatus('failed', $e->getMessage());
        } finally {
            $this->cleanStagingDir($stagingDir);
        }
    }

    private function restoreClassicFiles(string $archivePath, bool $deleteExistingFiles): void
    {
        if (!$deleteExistingFiles) {
            $this->mergeClassicFiles($archivePath);

            return;
        }

        $incoming = '.restore-' . $this->record->id;
        $aside = '.pre-restore-' . $this->record->id;
        $skip = [$incoming, $aside];

        $this->removeHomeEntry($incoming);
        $this->removeHomeEntry($aside);
        $this->extractHomeArchive($archivePath, $this->userPath($incoming));

        try {
            $this->swapHome($incoming, $aside, $skip);
        } catch (Throwable $e) {
            $this->attemptRollback(fn () => $this->rollbackHome($incoming, $aside, $skip));

            throw $this->restoreFailure($e);
        }

        $this->discardHomeAside($aside);
        $this->project->fixPermissions();
    }

    /** The scratch directory goes whether the merge lands or not; the live home is only ever added to. */
    private function mergeClassicFiles(string $archivePath): void
    {
        $incoming = '.restore-' . $this->record->id;

        $this->removeHomeEntry($incoming);
        try {
            $this->mergeIntoHome($archivePath, $incoming);
        } finally {
            $this->removeHomeEntry($incoming);
        }

        $this->project->fixPermissions();
    }

    /**
     * @param Collection<int, BackupItem> $selectedItems
     * @param array<int, string> $stagedPaths
     */
    private function restoreClassicDatabases(Collection $selectedItems, array $stagedPaths): void
    {
        foreach ($selectedItems as $item) {
            if (($item->details['type'] ?? null) !== 'database') {
                continue;
            }

            $name = (string) ($item->details['name'] ?? '');
            $this->ensureDatabaseRow($name);
            $this->restoreDatabase($name, $stagedPaths[$item->id]);
        }
    }

    /** A database dropped since the backup is recreated, without grants, so its data can come back. */
    private function ensureDatabaseRow(string $name): void
    {
        if ($this->databaseRowExists($name)) {
            return;
        }

        $model = $this->project->model();
        Log::warning('Restoring a backup database whose row no longer exists', [
            'username' => $this->project->username(),
            'database' => $name,
        ]);

        if (!$this->databaseExists($name)) {
            $this->createDatabase($name);
        }
        MysqlDatabase::create(['user_id' => $model->id, 'database' => $name]);
    }

    /**
     * @return Collection<int, BackupItem>
     */
    private function restorableItems(array $only, array $exclude): Collection
    {
        $selectedItems = $this->selectItems($this->record->items, $only, $exclude);
        if (
            $selectedItems->isEmpty()
            && ($this->isFilterNonEmpty($only) || $this->isFilterNonEmpty($exclude))
        ) {
            throw new \RuntimeException('No backup items match the restore filter');
        }

        return $selectedItems;
    }

    /**
     * @param Collection<int, BackupItem> $selectedItems
     */
    private function restoreWhileStopped(Collection $selectedItems, BackupStorage $storage, string $stagingDir): void
    {
        $stagedPaths = $this->downloadSelectedItems($selectedItems, $storage, $stagingDir);

        $filesItem = $this->findItemByType($selectedItems, 'files');
        if ($filesItem !== null) {
            $this->swapInProjectArchive($stagedPaths[$filesItem->id]);
        }

        $this->restoreComponents($selectedItems, $stagedPaths, $stagingDir, $filesItem !== null);

        if ($filesItem !== null) {
            $this->discardProjectAside(self::PROJECT_PRE_RESTORE);
        }

        $this->composeStart();
    }

    private function swapInProjectArchive(string $archivePath): void
    {
        $this->extractProjectArchive($archivePath, self::PROJECT_INCOMING);

        try {
            $this->swapProject(self::PROJECT_INCOMING, self::PROJECT_PRE_RESTORE);
        } catch (Throwable $e) {
            $this->rollBackProjectFiles('');

            throw new \RuntimeException('Project swap failed: ' . $e->getMessage());
        }
    }

    /**
     * Restore the selected volumes and databases; on the first failure undo every component restored so far.
     *
     * @param Collection<int, BackupItem> $selectedItems
     * @param array<int, string> $stagedPaths
     */
    private function restoreComponents(
        Collection $selectedItems,
        array $stagedPaths,
        string $stagingDir,
        bool $filesSwapped,
    ): void {
        $restoredVolumes = [];
        $databaseBackups = [];

        try {
            foreach ($selectedItems as $item) {
                $type = $item->details['type'] ?? null;
                if ($type === 'volume') {
                    $restoredVolumes[] = $this->restoreVolumeItem($item, $stagedPaths[$item->id]);
                } elseif ($type === 'database') {
                    $name = (string) ($item->details['name'] ?? '');
                    $this->restoreDatabaseItem($name, $stagedPaths[$item->id], $stagingDir, $databaseBackups);
                }
            }
        } catch (Throwable $e) {
            $this->rollBackComponents($restoredVolumes, $databaseBackups, $filesSwapped);

            throw $this->restoreFailure($e);
        }
    }

    private function restoreFailure(Throwable $e): \RuntimeException
    {
        if ($this->rollbackFailed) {
            return new \RuntimeException(
                'Restore failed and rollback was incomplete: ' . $e->getMessage(),
            );
        }

        return new \RuntimeException('Restore failed: ' . $e->getMessage());
    }

    /** @return string the volume's docker name */
    private function restoreVolumeItem(BackupItem $item, string $archivePath): string
    {
        $dockerName = (string) ($item->details['docker_name'] ?? '');
        $this->ensureVolume($dockerName);
        try {
            $this->restoreVolume($dockerName, $archivePath);
        } catch (Throwable $e) {
            $this->attemptRollback(fn () => $this->rollbackVolume($dockerName));
            throw $e;
        }

        return $dockerName;
    }

    /**
     * Records the pre-restore dump in $databaseBackups for a later rollback; a database restored
     * back from it on its own failure is taken off that list.
     *
     * @param array<string, string> $databaseBackups
     */
    private function restoreDatabaseItem(string $name, string $sqlGzPath, string $stagingDir, array &$databaseBackups): void
    {
        $preRestoreDump = $stagingDir . 'pre-restore-' . $name . '.sql.gz';
        $this->dumpDatabase($name, $preRestoreDump);
        $databaseBackups[$name] = $preRestoreDump;
        try {
            $this->restoreDatabase($name, $sqlGzPath);
        } catch (Throwable $e) {
            $this->attemptRollback(fn () => $this->restoreDatabase($name, $preRestoreDump));
            unset($databaseBackups[$name]);
            throw $e;
        }
    }

    /**
     * @param list<string> $restoredVolumes
     * @param array<string, string> $databaseBackups database name => pre-restore dump
     */
    private function rollBackComponents(array $restoredVolumes, array $databaseBackups, bool $filesSwapped): void
    {
        foreach (array_reverse($restoredVolumes) as $dockerName) {
            $this->attemptRollback(fn () => $this->rollbackVolume($dockerName));
        }

        foreach ($databaseBackups as $name => $dumpPath) {
            $this->attemptRollback(fn () => $this->restoreDatabase($name, $dumpPath));
        }

        if ($filesSwapped) {
            $this->rollBackProjectFiles(' after component restore error');
        }
    }

    /** Run one rollback step; a failure is recorded, not thrown. */
    private function attemptRollback(callable $step): void
    {
        try {
            $step();
        } catch (Throwable) {
            $this->rollbackFailed = true;
        }
    }

    /** Put the pre-restore ~/project back; a failure is fatal, since the live content is then only in the aside. */
    private function rollBackProjectFiles(string $context): void
    {
        try {
            $this->rollbackProject(self::PROJECT_PRE_RESTORE);
        } catch (Throwable $rollbackError) {
            $this->rollbackFailed = true;
            throw new \RuntimeException(
                'Project rollback failed' . $context . '; live content may be in ' . self::PROJECT_PRE_RESTORE . ': ' . $rollbackError->getMessage(),
            );
        }
    }

    // --- downtime ---

    /**
     * Stop compose, create scratch dir, run $work, always clean scratch.
     * On failure: best-effort composeStart when stopped and $shouldRestartOnFailure allows it, then rethrow.
     *
     * @param callable(string): void $work
     * @param (callable(): bool)|null $shouldRestartOnFailure null = always restart if stopped
     */
    private function withComposeStopped(
        int $backupId,
        callable $work,
        ?callable $shouldRestartOnFailure = null,
    ): void {
        $stopRan = false;
        $stagingDir = null;
        $username = $this->project->username();

        try {
            $this->composeStop();
            $stopRan = true;

            $stagingDir = $this->stagingDir($username, $backupId);
            $this->ensureDirectory($stagingDir);

            $work($stagingDir);
        } catch (Throwable $e) {
            $restart = $shouldRestartOnFailure === null || $shouldRestartOnFailure();
            if ($stopRan && $restart) {
                $this->tryComposeStart($backupId);
            }

            throw $e;
        } finally {
            if ($stagingDir !== null) {
                $this->cleanStagingDir($stagingDir);
            }
        }
    }

    private function tryComposeStart(int $backupId): void
    {
        try {
            $this->composeStart();
        } catch (Throwable $e) {
            Log::warning('Backup composeStart failed', [
                'username' => $this->project->username(),
                'backup_id' => $backupId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function estimateRequiredBytes(): int
    {
        $estimate = 1024 * 1024 * 1024;
        if (!$this->record->includeDatabases()) {
            return $estimate;
        }

        $volumes = $this->project->runsInDind() ? count($this->namedVolumes()) : 0;

        return $estimate + ($volumes + count($this->databaseNames())) * (100 * 1024 * 1024);
    }

    // --- filters ---

    /**
     * @param Collection<int, BackupItem> $items
     * @return Collection<int, BackupItem>
     */
    private function selectItems(Collection $items, array $only, array $exclude): Collection
    {
        if (!$this->isFilterNonEmpty($only) && !$this->isFilterNonEmpty($exclude)) {
            return $items;
        }

        if ($this->isFilterNonEmpty($only)) {
            return $items->filter(
                fn (BackupItem $item): bool => $this->itemMatchesFilter($item, $only),
            )->values();
        }

        return $items->filter(
            fn (BackupItem $item): bool => !$this->itemMatchesFilter($item, $exclude),
        )->values();
    }

    private function itemMatchesFilter(BackupItem $item, array $filter): bool
    {
        $type = $item->details['type'] ?? null;
        $name = $item->details['name'] ?? null;

        return match ($type) {
            'files' => ($filter['files'] ?? false) === true,
            'volume' => is_string($name) && in_array($name, $filter['volumes'] ?? [], true),
            'database' => is_string($name) && in_array($name, $filter['databases'] ?? [], true),
            default => false,
        };
    }

    /**
     * @param Collection<int, BackupItem> $items
     */
    private function sumSelectedBytes(Collection $items): int
    {
        return (int) $items->sum('size_bytes');
    }

    /**
     * @param Collection<int, BackupItem> $items
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

    private function isFilterNonEmpty(array $filter): bool
    {
        if (($filter['files'] ?? false) === true) {
            return true;
        }

        if (($filter['volumes'] ?? []) !== []) {
            return true;
        }

        if (($filter['databases'] ?? []) !== []) {
            return true;
        }

        return false;
    }

    // --- transfer ---

    /**
     * @return list<array{path: string, file: string, type: string, name: string, scope?: string, docker_name?: string}>
     */
    private function snapshotManifest(string $stagingDir): array
    {
        $files = [];

        if ($this->record->includeFiles()) {
            $files[] = $this->project->runsInDind()
                ? [
                    'path' => $stagingDir . 'files.tar.gz',
                    'file' => 'files.tar.gz',
                    'type' => 'files',
                    'name' => 'files',
                ]
                : [
                    'path' => $stagingDir . 'home.tar.gz',
                    'file' => 'home.tar.gz',
                    'type' => 'files',
                    'name' => 'home',
                    'scope' => 'home',
                ];
        }

        if (!$this->record->includeDatabases()) {
            return $files;
        }

        if ($this->project->runsInDind()) {
            foreach ($this->namedVolumes() as $volume) {
                $files[] = [
                    'path' => $stagingDir . 'volume-' . $volume['name'] . '.tar.gz',
                    'file' => 'volume-' . $volume['name'] . '.tar.gz',
                    'type' => 'volume',
                    'name' => $volume['name'],
                    'docker_name' => $volume['docker_name'],
                ];
            }
        }

        foreach ($this->databaseNames() as $database) {
            $files[] = [
                'path' => $stagingDir . 'database-' . $database . '.sql.gz',
                'file' => 'database-' . $database . '.sql.gz',
                'type' => 'database',
                'name' => $database,
            ];
        }

        return $files;
    }

    private function uploadSnapshot(
        BackupContainer $container,
        BackupStorage $storage,
        string $stagingDir,
    ): void {
        $record = $this->record;
        $keyPrefix = $this->storagePrefix($container, $record->username, $record->id);

        foreach ($this->snapshotManifest($stagingDir) as $file) {
            $sha256 = $this->sha256Of($file['path']);
            $sizeBytes = filesize($file['path']);
            if ($sizeBytes === false) {
                throw new \RuntimeException('Failed to read staging file size: ' . $file['path']);
            }

            $key = $keyPrefix . $file['file'];
            $this->putFile($storage, $key, $file['path']);
            $this->saveItem($key, $sizeBytes, $file, $sha256);
        }
    }

    private function putFile(BackupStorage $storage, string $key, string $path): void
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Failed to open staging file: ' . $path);
        }

        try {
            $storage->put($key, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @param array{type: string, name: string, scope?: string, docker_name?: string} $file
     */
    private function saveItem(string $key, int $sizeBytes, array $file, string $sha256): void
    {
        $details = [
            'type' => $file['type'],
            'name' => $file['name'],
            'sha256' => $sha256,
        ];
        if (isset($file['docker_name'])) {
            $details['docker_name'] = $file['docker_name'];
        }
        if (isset($file['scope'])) {
            $details['scope'] = $file['scope'];
        }

        $item = new BackupItem();
        $item->backup_id = $this->record->id;
        $item->remote_path = $key;
        $item->size_bytes = $sizeBytes;
        $item->details = $details;
        $item->save();
    }

    /**
     * @param Collection<int, BackupItem> $items
     * @return array<int, string>
     */
    private function downloadSelectedItems(Collection $items, BackupStorage $storage, string $stagingDir): array
    {
        $stagedPaths = [];

        foreach ($items as $item) {
            $localPath = $stagingDir . basename($item->remote_path);
            $this->downloadFile($storage, $item->remote_path, $localPath);
            $this->assertChecksum($item, $localPath);
            $stagedPaths[$item->id] = $localPath;
        }

        return $stagedPaths;
    }

    private function downloadFile(BackupStorage $storage, string $remotePath, string $localPath): void
    {
        $stream = $storage->readStream($remotePath);
        $dest = fopen($localPath, 'wb');
        if ($dest === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new \RuntimeException('Failed to open staging file: ' . $localPath);
        }

        try {
            stream_copy_to_stream($stream, $dest);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_resource($dest)) {
                fclose($dest);
            }
        }
    }

    private function assertChecksum(BackupItem $item, string $localPath): void
    {
        $expectedSha256 = $item->details['sha256'] ?? null;
        if (!is_string($expectedSha256) || $expectedSha256 === '') {
            throw new \RuntimeException('Backup item is missing sha256 checksum.');
        }

        if (!hash_equals($expectedSha256, $this->sha256Of($localPath))) {
            throw new \RuntimeException('Checksum mismatch');
        }
    }

    private function sha256Of(string $path): string
    {
        $sha256 = hash_file('sha256', $path);
        if ($sha256 === false) {
            throw new \RuntimeException('Failed to hash staging file: ' . $path);
        }

        return $sha256;
    }

    private function markBackupFailed(
        BackupContainer $container,
        BackupStorage $storage,
        Throwable $e,
    ): void {
        $record = $this->record;
        $cleanupError = null;

        try {
            $storage->deletePrefix($this->storagePrefix($container, $record->username, $record->id));
            BackupItem::query()->where('backup_id', $record->id)->delete();
        } catch (Throwable $cleanup) {
            $cleanupError = $cleanup->getMessage();
        }

        $record->setBackupStatus('failed');
        $record->error = $cleanupError === null
            ? $e->getMessage()
            : $e->getMessage() . '; cleanup failed: ' . $cleanupError;
        $record->save();
    }

    // --- paths & scratch ---

    private function stagingDir(string $username, int $backupId): string
    {
        return self::STAGING_ROOT . '/' . $username . '/' . $backupId . '/';
    }

    private function storagePrefix(BackupContainer $container, string $username, int $backupId): string
    {
        return $this->credentialPrefix($container) . $username . '/' . $backupId . '/';
    }

    private function credentialPrefix(BackupContainer $container): string
    {
        $credentials = $container->credentials;
        if (!is_array($credentials)) {
            return '';
        }

        $prefix = $credentials['prefix'] ?? '';
        if (!is_string($prefix) || $prefix === '') {
            return '';
        }

        return rtrim($prefix, '/') . '/';
    }

    /**
     * The @ keeps Laravel from promoting "mkdir(): Permission denied" to an ErrorException when the
     * queue worker runs as www-data and the bind-mount is still root-owned; sudo then creates it.
     */
    private function ensureDirectory(string $path): void
    {
        $normalized = $this->assertStagingPath($path);
        if (is_dir($path) || is_dir($normalized)) {
            return;
        }

        if (@mkdir($path, 0755, true) || is_dir($path)) {
            return;
        }

        $this->project->system()->exec(['sudo', 'mkdir', '-p', $normalized]);
        $this->project->system()->exec(['sudo', 'chown', '-R', 'www-data:www-data', $normalized]);

        if (!is_dir($path) && !is_dir($normalized)) {
            throw new \RuntimeException('Failed to create staging directory: ' . $path);
        }
    }

    private function cleanStagingDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        $this->unlinkFiles($path, $entries);
        @rmdir($path);
        $this->removeParentIfEmpty($path);

        if (is_dir($path)) {
            $normalized = $this->assertStagingPath($path);
            $this->project->system()->exec(['sudo', 'rm', '-rf', $normalized]);
            $this->removeParentIfEmpty($path);
        }
    }

    /**
     * @param list<string> $entries
     */
    private function unlinkFiles(string $dir, array $entries): void
    {
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $dir . $entry;
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
    }

    /** The per-account directory above a staging dir, never the staging root itself. */
    private function removeParentIfEmpty(string $path): void
    {
        $parent = dirname(rtrim($path, '/'));
        if ($parent !== self::STAGING_ROOT && is_dir($parent) && $this->isEmptyDirectory($parent)) {
            @rmdir($parent);
        }
    }

    private function assertStagingPath(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        $root = self::STAGING_ROOT;
        if ($normalized === $root || !str_starts_with($normalized, $root . '/')) {
            throw new \RuntimeException('Invalid staging path: ' . $path);
        }
        if (str_contains($normalized, '..')) {
            throw new \RuntimeException('Invalid staging path: ' . $path);
        }

        return $normalized;
    }

    private function isEmptyDirectory(string $path): bool
    {
        $entries = scandir($path);

        return $entries !== false && count($entries) === 2;
    }

    // --- DinD / host forwards (overridable in tests) ---

    protected function resolveStorage(BackupContainer $container): BackupStorage
    {
        return $container->storage();
    }

    protected function composeStop(): void
    {
        $this->containers()->composeStop();
    }

    protected function composeStart(): void
    {
        $this->containers()->composeStart();
    }

    /**
     * @return list<array{name: string, docker_name: string}>
     */
    protected function namedVolumes(): array
    {
        return $this->volumeArchive()->namedVolumes();
    }

    protected function tarVolume(string $dockerName, string $dest): void
    {
        $this->volumeArchive()->tarVolume($dockerName, $dest);
    }

    protected function ensureVolume(string $dockerName): void
    {
        $this->volumeArchive()->ensureVolume($dockerName);
    }

    protected function restoreVolume(string $dockerName, string $archivePath): void
    {
        $this->volumeArchive()->restoreVolume($dockerName, $archivePath);
    }

    protected function rollbackVolume(string $dockerName): void
    {
        $this->volumeArchive()->rollbackVolume($dockerName);
    }

    /**
     * @return list<string>
     */
    protected function databaseNames(): array
    {
        $model = $this->project->model();
        $model->loadMissing('mysqlDatabases');

        /** @var list<string> $names */
        $names = $model->mysqlDatabases
            ->pluck('database')
            ->values()
            ->all();

        return $names;
    }

    protected function dumpDatabase(string $name, string $dest): void
    {
        $this->project->system()->mysql()->dumpToGzip($name, $dest);
    }

    protected function restoreDatabase(string $name, string $sqlGzPath): void
    {
        $this->project->system()->mysql()->restoreFromGzip($name, $sqlGzPath);
    }

    /** Runs on the job budget: a large ~/project outlasts execOnHost()'s 600 s default. */
    protected function tarProject(string $dest): void
    {
        $home = rtrim($this->project->homeDirPath(), '/');
        $this->project->system()->execOnHost([
            'tar',
            '-C',
            $home,
            '-czf',
            $dest,
            'project',
        ], [], JobBudget::COMMAND_SECONDS);
    }

    /** The whole home, preserving ownership, with the push and restore scratch directories left out. */
    protected function tarHome(string $dest): void
    {
        $home = rtrim($this->project->homeDirPath(), '/');
        $this->project->system()->execOnHost([
            'tar',
            '-C',
            $home,
            '-czf',
            $dest,
            '--exclude=./.incoming',
            '--exclude=./.pre-push',
            '--exclude=./.pre-push-db',
            '.',
        ], [], JobBudget::COMMAND_SECONDS);
    }

    /**
     * The home root is root-owned (Project::createHomeDirectory chowns it root:root), so the
     * scratch directory is created by root. A leftover from an earlier failed run is removed
     * first without following it, so a stale symlink cannot become the extraction target.
     * It stays closed to the account while root extracts, so no directory tar has already
     * handed over can be swapped for a link under a later member.
     */
    protected function extractHomeArchive(string $archivePath, string $targetDir): void
    {
        $this->project->system()->execOnHost(['mkdir', '-p', '-m', '700', $targetDir]);
        $this->project->system()->execOnHost(
            ['tar', '-xzf', $archivePath, '-C', $targetDir],
            [],
            JobBudget::COMMAND_SECONDS,
        );
    }

    /**
     * delete_existing_files=false: the live home stays writable by the account's PHP and cron, so
     * root never extracts into it. The account unpacks and merges the archive. {@see HomeMerge}
     */
    protected function mergeIntoHome(string $archivePath, string $incomingDir): void
    {
        $this->homeMerge()->merge($archivePath, $this->userPath($incomingDir));
    }

    /** Remove a pre-existing scratch entry, link or not, without following a symlink at it. */
    protected function removeHomeEntry(string $entry): void
    {
        $this->project->system()->execOnHost(
            ['rm', '-rf', '--', $this->userPath($entry)],
            [],
            JobBudget::COMMAND_SECONDS,
        );
    }

    /**
     * @param list<string> $skipEntries
     */
    protected function swapHome(string $incomingDir, string $asideDir, array $skipEntries): void
    {
        $this->homeSwap()->swap(
            $this->userPath($incomingDir),
            $this->userPath($asideDir),
            $skipEntries,
            'restore',
        );
    }

    /**
     * @param list<string> $skipEntries
     */
    protected function rollbackHome(string $incomingDir, string $asideDir, array $skipEntries): void
    {
        $this->homeSwap()->rollback(
            $this->userPath($incomingDir),
            $this->userPath($asideDir),
            $skipEntries,
            'restore',
        );
    }

    protected function discardHomeAside(string $asideDir): void
    {
        $this->project->system()->execOnHost(
            ['rm', '-rf', '--', $this->userPath($asideDir)],
            [],
            JobBudget::COMMAND_SECONDS,
        );
    }

    protected function databaseExists(string $name): bool
    {
        return $this->project->system()->mysql()->databases()->databaseExists($name);
    }

    protected function databaseRowExists(string $name): bool
    {
        $model = $this->project->model();
        $model->loadMissing('mysqlDatabases');

        return $model->mysqlDatabases->contains('database', $name);
    }

    protected function createDatabase(string $name): void
    {
        $this->project->system()->mysql()->databases()->createDatabase($name);
    }

    private function homeSwap(): HomeSwap
    {
        return new HomeSwap(
            $this->project->system(),
            rtrim($this->project->homeDirPath(), '/'),
            JobBudget::COMMAND_SECONDS,
        );
    }

    private function homeMerge(): HomeMerge
    {
        [$uid, $gid] = $this->project->model()->linuxIds();

        return new HomeMerge(
            $this->project->system(),
            rtrim($this->project->homeDirPath(), '/'),
            (string) $uid,
            (string) $gid,
            JobBudget::COMMAND_SECONDS,
        );
    }

    /**
     * --strip-components drops the archived `project` entry, so $incomingDir keeps mkdir's root owner until it
     * is handed over after tar: root must not extract into a directory the account can plant links in. A leftover
     * from a failed restore was already handed over, so it goes first, and the fresh one stays closed to the
     * account until tar is done, so no directory tar has already handed over can be swapped for a link.
     */
    protected function extractProjectArchive(string $archivePath, string $incomingDir): void
    {
        $incoming = $this->userPath($incomingDir);
        $this->project->system()->execOnHost(['rm', '-rf', '--', $incoming], [], JobBudget::COMMAND_SECONDS);
        $this->project->system()->execOnHost(['mkdir', '-m', '700', '--', $incoming]);
        $this->project->system()->execOnHost([
            'tar',
            '-xzf',
            $archivePath,
            '-C',
            $incoming,
            '--strip-components=1',
        ], [], JobBudget::COMMAND_SECONDS);
        $this->project->system()->execOnHost(['chmod', '755', $incoming]);
        $owner = $this->project->model()->getChownString();
        if ($owner !== null) {
            $this->project->system()->execOnHost(['chown', $owner, $incoming]);
        }
    }

    protected function swapProject(string $incomingDir, string $asideDir): void
    {
        $home = rtrim($this->project->homeDirPath(), '/');
        $live = $home . '/project';
        $incoming = $this->userPath($incomingDir);
        $aside = $this->userPath($asideDir);

        if ($this->pathExistsOnHost($aside)) {
            throw new \RuntimeException(
                'Pre-restore aside already exists at ' . $aside . '; refusing to overwrite',
            );
        }

        $this->project->system()->execOnHost(['mv', $live, $aside]);
        $this->project->system()->execOnHost(['mv', $incoming, $live]);
    }

    protected function rollbackProject(string $asideDir): void
    {
        $home = rtrim($this->project->homeDirPath(), '/');
        $live = $home . '/project';
        $aside = $this->userPath($asideDir);

        if ($this->pathExistsOnHost($live)) {
            $this->project->system()->execOnHost(['rm', '-rf', $live]);
        }

        $this->project->system()->execOnHost(['mv', $aside, $live]);
    }

    protected function discardProjectAside(string $asideDir): void
    {
        $this->project->system()->execOnHost(['rm', '-rf', $this->userPath($asideDir)]);
    }

    private function userPath(string $relative): string
    {
        return rtrim($this->project->homeDirPath(), '/') . '/' . ltrim($relative, '/');
    }

    private function pathExistsOnHost(string $path): bool
    {
        return $this->project->system()->runProcess([
            'sudo',
            'test',
            '-e',
            $path,
        ])->getExitCode() === 0;
    }

    private function requireDind(): Dind
    {
        $runtime = $this->project->runtime();
        if (!$runtime instanceof Dind) {
            throw new \RuntimeException('Backup requires a DinD project');
        }

        return $runtime;
    }

    private function containers(): ContainerOperations
    {
        $dind = $this->requireDind();

        return new ContainerOperations($dind, $dind->shell());
    }

    private function volumeArchive(): VolumeArchive
    {
        return new VolumeArchive($this->requireDind());
    }
}
