<?php

namespace App\System\Project;

use App\Integrations\Storage\BackupStorage;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\BackupItem;
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

    public function run(): void
    {
        $record = $this->record;
        $record->loadMissing('container');
        $container = $record->container;
        if ($container === null) {
            throw new \RuntimeException('Backup container not found for record ' . $record->id);
        }

        $storage = $this->resolveStorage($container);

        if ($this->project->model()->getTemplate() !== 'dind') {
            $record->setBackupStatus('failed');
            $record->error = 'not supported';
            $record->save();

            throw new \RuntimeException('not supported');
        }

        try {
            $this->project->system()->filesystem()->assertFreeSpace(
                $this->estimateRequiredBytes(),
            );

            $record->setBackupStatus('running');
            $record->error = null;
            $record->save();

            $this->withComposeStopped($record->id, function (string $stagingDir) use (
                $record,
                $container,
                $storage,
            ): void {
                $this->tarProject($stagingDir . 'files.tar.gz');

                foreach ($this->namedVolumes() as $volume) {
                    $this->tarVolume(
                        $volume['docker_name'],
                        $stagingDir . 'volume-' . $volume['name'] . '.tar.gz',
                    );
                }

                foreach ($this->databaseNames() as $database) {
                    $this->dumpDatabase(
                        $database,
                        $stagingDir . 'database-' . $database . '.sql.gz',
                    );
                }

                // Upload while the stack is running again — same order as before.
                $this->tryComposeStart($record->id);
                $this->uploadSnapshot($container, $storage, $stagingDir);
            });

            $record->setBackupStatus('completed');
            $record->error = null;
            $record->save();
        } catch (Throwable $e) {
            $this->markBackupFailed($container, $storage, $e);
        }
    }

    public function restore(array $only = [], array $exclude = []): void
    {
        if ($this->project->model()->getTemplate() !== 'dind') {
            throw new \RuntimeException('not supported');
        }

        $this->record->loadMissing(['container', 'items']);
        if ($this->record->backupStatus() !== 'completed' || $this->record->items->isEmpty()) {
            throw new \RuntimeException('Backup is not complete');
        }

        if ($this->isFilterNonEmpty($only) && $this->isFilterNonEmpty($exclude)) {
            throw new \RuntimeException('Cannot combine include and exclude');
        }

        $this->record->restore_details = ['only' => $only, 'exclude' => $exclude];
        $this->record->setRestoreStatus('running');
        $this->record->error = null;
        $this->record->save();

        $this->restoreBackup($only, $exclude);
    }

    public function delete(): void
    {
        $record = $this->record;
        $record->loadMissing('container');
        $container = $record->container;
        if ($container === null) {
            throw new \RuntimeException('Backup container not found for record ' . $record->id);
        }

        $storage = $this->resolveStorage($container);
        $record->setDeleteStatus('running');
        $record->error = null;
        $record->save();

        try {
            $storage->deletePrefix($this->storagePrefix($container, $record->username, $record->id));
        } catch (Throwable $e) {
            $record->setDeleteStatus('failed');
            $record->error = $e->getMessage();
            $record->save();

            return;
        }

        $record->delete();
    }

    // --- restore mechanics ---

    private function restoreBackup(array $only, array $exclude): void
    {
        $record = $this->record;

        $container = $record->container;
        if ($container === null) {
            throw new \RuntimeException('Backup container not found for record ' . $record->id);
        }

        $storage = $this->resolveStorage($container);
        $rollbackFailed = false;

        try {
            $selectedItems = $this->selectItems($record->items, $only, $exclude);
            if (
                $selectedItems->isEmpty()
                && ($this->isFilterNonEmpty($only) || $this->isFilterNonEmpty($exclude))
            ) {
                throw new \RuntimeException('No backup items match the restore filter');
            }

            $this->project->system()->filesystem()->assertFreeSpace(
                $this->sumSelectedBytes($selectedItems),
            );

            $this->withComposeStopped(
                $record->id,
                function (string $stagingDir) use (
                    $selectedItems,
                    $storage,
                    &$rollbackFailed,
                ): void {
                    $stagedPaths = $this->downloadSelectedItems($selectedItems, $storage, $stagingDir);

                    $filesItem = $this->findItemByType($selectedItems, 'files');
                    $filesSwapped = false;
                    if ($filesItem !== null) {
                        $archivePath = $stagedPaths[$filesItem->id];
                        $this->extractProjectArchive($archivePath, self::PROJECT_INCOMING);

                        try {
                            $this->swapProject(self::PROJECT_INCOMING, self::PROJECT_PRE_RESTORE);
                            $filesSwapped = true;
                        } catch (Throwable $e) {
                            try {
                                $this->rollbackProject(self::PROJECT_PRE_RESTORE);
                            } catch (Throwable $rollbackError) {
                                $rollbackFailed = true;
                                throw new \RuntimeException(
                                    'Project rollback failed; live content may be in ' . self::PROJECT_PRE_RESTORE . ': ' . $rollbackError->getMessage(),
                                );
                            }

                            throw new \RuntimeException('Project swap failed: ' . $e->getMessage());
                        }
                    }

                    $restoredVolumes = [];
                    $databaseBackups = [];

                    try {
                        foreach ($selectedItems as $item) {
                            $type = $item->details['type'] ?? null;
                            if ($type === 'volume') {
                                $dockerName = (string) ($item->details['docker_name'] ?? '');
                                $this->ensureVolume($dockerName);
                                try {
                                    $this->restoreVolume($dockerName, $stagedPaths[$item->id]);
                                } catch (Throwable $e) {
                                    try {
                                        $this->rollbackVolume($dockerName);
                                    } catch (Throwable) {
                                        $rollbackFailed = true;
                                    }
                                    throw $e;
                                }
                                $restoredVolumes[] = $dockerName;
                            } elseif ($type === 'database') {
                                $name = (string) ($item->details['name'] ?? '');
                                $preRestoreDump = $stagingDir . 'pre-restore-' . $name . '.sql.gz';
                                $this->dumpDatabase($name, $preRestoreDump);
                                $databaseBackups[$name] = $preRestoreDump;
                                try {
                                    $this->restoreDatabase($name, $stagedPaths[$item->id]);
                                } catch (Throwable $e) {
                                    try {
                                        $this->restoreDatabase($name, $preRestoreDump);
                                    } catch (Throwable) {
                                        $rollbackFailed = true;
                                    }
                                    unset($databaseBackups[$name]);
                                    throw $e;
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        foreach (array_reverse($restoredVolumes) as $dockerName) {
                            try {
                                $this->rollbackVolume($dockerName);
                            } catch (Throwable) {
                                $rollbackFailed = true;
                            }
                        }

                        foreach ($databaseBackups as $name => $dumpPath) {
                            try {
                                $this->restoreDatabase($name, $dumpPath);
                            } catch (Throwable) {
                                $rollbackFailed = true;
                            }
                        }

                        if ($filesSwapped) {
                            try {
                                $this->rollbackProject(self::PROJECT_PRE_RESTORE);
                            } catch (Throwable $rollbackError) {
                                $rollbackFailed = true;
                                throw new \RuntimeException(
                                    'Project rollback failed after component restore error; live content may be in ' . self::PROJECT_PRE_RESTORE . ': ' . $rollbackError->getMessage(),
                                );
                            }
                        }

                        if ($rollbackFailed) {
                            throw new \RuntimeException(
                                'Restore failed and rollback was incomplete: ' . $e->getMessage(),
                            );
                        }

                        throw new \RuntimeException('Restore failed: ' . $e->getMessage());
                    }

                    if ($filesItem !== null) {
                        $this->discardProjectAside(self::PROJECT_PRE_RESTORE);
                    }

                    $this->composeStart();
                },
                function () use (&$rollbackFailed): bool {
                    return !$rollbackFailed;
                },
            );

            $record->setRestoreStatus('completed');
            $record->error = null;
            $record->save();
        } catch (Throwable $e) {
            $record->setRestoreStatus('failed');
            $record->error = $e->getMessage();
            $record->save();
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

        return $estimate + (count($this->namedVolumes()) + count($this->databaseNames())) * (100 * 1024 * 1024);
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
     * @return list<array{path: string, file: string, type: string, name: string, docker_name?: string}>
     */
    private function snapshotManifest(string $stagingDir): array
    {
        $files = [
            [
                'path' => $stagingDir . 'files.tar.gz',
                'file' => 'files.tar.gz',
                'type' => 'files',
                'name' => 'files',
            ],
        ];

        foreach ($this->namedVolumes() as $volume) {
            $files[] = [
                'path' => $stagingDir . 'volume-' . $volume['name'] . '.tar.gz',
                'file' => 'volume-' . $volume['name'] . '.tar.gz',
                'type' => 'volume',
                'name' => $volume['name'],
                'docker_name' => $volume['docker_name'],
            ];
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
        $objectKeyPrefix = $this->objectKeyPrefix($container, $record->username, $record->id);

        foreach ($this->snapshotManifest($stagingDir) as $file) {
            $sha256 = hash_file('sha256', $file['path']);
            if ($sha256 === false) {
                throw new \RuntimeException('Failed to hash staging file: ' . $file['path']);
            }

            $sizeBytes = filesize($file['path']);
            if ($sizeBytes === false) {
                throw new \RuntimeException('Failed to read staging file size: ' . $file['path']);
            }

            $key = $objectKeyPrefix . $file['file'];
            $stream = fopen($file['path'], 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Failed to open staging file: ' . $file['path']);
            }

            try {
                $storage->put($key, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $details = [
                'type' => $file['type'],
                'name' => $file['name'],
                'sha256' => $sha256,
            ];
            if (isset($file['docker_name'])) {
                $details['docker_name'] = $file['docker_name'];
            }

            $item = new BackupItem();
            $item->backup_id = $record->id;
            $item->remote_path = $key;
            $item->size_bytes = $sizeBytes;
            $item->details = $details;
            $item->save();
        }
    }

    /**
     * @param Collection<int, BackupItem> $items
     * @return array<int, string>
     */
    private function downloadSelectedItems(Collection $items, BackupStorage $storage, string $stagingDir): array
    {
        $stagedPaths = [];

        foreach ($items as $item) {
            $basename = basename($item->remote_path);
            $localPath = $stagingDir . $basename;
            $stream = $storage->readStream($item->remote_path);
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

            $expectedSha256 = $item->details['sha256'] ?? null;
            if (!is_string($expectedSha256) || $expectedSha256 === '') {
                throw new \RuntimeException('Backup item is missing sha256 checksum.');
            }

            $actualSha256 = hash_file('sha256', $localPath);
            if ($actualSha256 === false) {
                throw new \RuntimeException('Failed to hash staging file: ' . $localPath);
            }

            if (!hash_equals($expectedSha256, $actualSha256)) {
                throw new \RuntimeException('Checksum mismatch');
            }

            $stagedPaths[$item->id] = $localPath;
        }

        return $stagedPaths;
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

    private function objectKeyPrefix(BackupContainer $container, string $username, int $backupId): string
    {
        return $this->storagePrefix($container, $username, $backupId);
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

    private function ensureDirectory(string $path): void
    {
        $normalized = $this->assertStagingPath($path);
        if (is_dir($path) || is_dir($normalized)) {
            return;
        }

        // @ suppresses the warning Laravel would promote to ErrorException
        // ("mkdir(): Permission denied") when the queue worker runs as www-data and the
        // bind-mount is still root-owned.
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

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $path . $entry;
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }

        @rmdir($path);

        $parent = dirname(rtrim($path, '/'));
        if ($parent !== self::STAGING_ROOT && is_dir($parent) && $this->isEmptyDirectory($parent)) {
            @rmdir($parent);
        }

        if (is_dir($path)) {
            $normalized = $this->assertStagingPath($path);
            $this->project->system()->exec(['sudo', 'rm', '-rf', $normalized]);
            if ($parent !== self::STAGING_ROOT && is_dir($parent) && $this->isEmptyDirectory($parent)) {
                @rmdir($parent);
            }
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
        ]);
    }

    protected function extractProjectArchive(string $archivePath, string $incomingDir): void
    {
        $incoming = $this->userPath($incomingDir);
        $this->project->system()->execOnHost(['mkdir', '-p', $incoming]);
        $this->project->system()->execOnHost([
            'tar',
            '-xzf',
            $archivePath,
            '-C',
            $incoming,
            '--strip-components=1',
        ]);
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
