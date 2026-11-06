<?php

namespace App\System\Project\Snapshot;

use App\Models\Snapshot;

/**
 * One directory per snapshot in core's storage volume: it survives a core restart, and the
 * account cannot reach it, so no dump replayed as root ever came from the account's side.
 */
class SnapshotStore
{
    private const string PRE_RESTORE_PATTERN = 'pre-restore-*.sql.gz';

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? storage_path('app/database-snapshots'), '/');
    }

    public function directory(Snapshot $snapshot): string
    {
        return $this->accountDirectory($snapshot->username).'/'.$snapshot->id.'/';
    }

    public function create(Snapshot $snapshot): string
    {
        $directory = $this->directory($snapshot);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Failed to create the snapshot directory '.$directory);
        }

        return $directory;
    }

    public function remove(Snapshot $snapshot): void
    {
        $directory = $this->directory($snapshot);
        if (! is_dir($directory)) {
            return;
        }

        $this->unlinkAll(glob($directory.'*') ?: []);
        if (! self::removedDirectory($directory)) {
            throw new \RuntimeException('Failed to remove the snapshot directory '.$directory);
        }
        @rmdir($this->accountDirectory($snapshot->username));
    }

    public function removePreRestoreDumps(Snapshot $snapshot): void
    {
        $this->unlinkAll(glob($this->directory($snapshot).self::PRE_RESTORE_PATTERN) ?: []);
    }

    public function freeBytes(): int
    {
        $path = $this->root;
        while (! is_dir($path) && dirname($path) !== $path) {
            $path = dirname($path);
        }

        $free = disk_free_space($path);
        if ($free === false) {
            throw new \RuntimeException('Could not determine free space for snapshots at '.$this->root);
        }

        return (int) $free;
    }

    private static function removedDirectory(string $directory): bool
    {
        return @rmdir($directory) || ! is_dir($directory);
    }

    private function accountDirectory(string $username): string
    {
        if ($username === '' || $username !== basename($username) || str_starts_with($username, '.')) {
            throw new \RuntimeException("Invalid snapshot owner '{$username}'");
        }

        return $this->root.'/'.$username;
    }

    /**
     * @param  list<string>  $paths
     */
    private function unlinkAll(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path) && ! @unlink($path) && is_file($path)) {
                throw new \RuntimeException('Failed to remove '.$path);
            }
        }
    }
}
