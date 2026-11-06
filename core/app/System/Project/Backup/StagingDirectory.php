<?php

namespace App\System\Project\Backup;

use App\System\Privileged;
use App\System\Project as UserProject;

/** The per-backup scratch directory in the core container that items are written to and read from. */
final class StagingDirectory
{
    private const string ROOT = '/var/tmp/panelalpha-backup';

    public function __construct(
        private UserProject $project,
    ) {}

    public function path(string $username, int $backupId): string
    {
        return self::ROOT.'/'.$username.'/'.$backupId.'/';
    }

    /**
     * The @ keeps Laravel from promoting "mkdir(): Permission denied" to an ErrorException when the
     * queue worker runs as www-data and the bind-mount is still root-owned; the root broker then creates it.
     */
    public function ensure(string $path): void
    {
        $normalized = $this->assertInsideRoot($path);
        if (is_dir($path) || is_dir($normalized)) {
            return;
        }

        if (@mkdir($path, 0755, true) || is_dir($path)) {
            return;
        }

        $this->project->system()->exec(Privileged::files()->makeDirectories($normalized));
        $this->project->system()->exec(Privileged::files()->changeOwnerRecursively('www-data:www-data', $normalized));

        if (! is_dir($path) && ! is_dir($normalized)) {
            throw new \RuntimeException('Failed to create staging directory: '.$path);
        }
    }

    public function clean(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        if ($this->removedWithoutRoot($path, $entries)) {
            return;
        }
        $normalized = $this->assertInsideRoot($path);
        $this->project->system()->exec(Privileged::files()->removeTree($normalized));
        $this->removeParentIfEmpty($path);
    }

    /**
     * @param  list<string>  $entries
     */
    private function removedWithoutRoot(string $path, array $entries): bool
    {
        $this->unlinkFiles($path, $entries);
        @rmdir($path);
        $this->removeParentIfEmpty($path);

        return ! is_dir($path);
    }

    /**
     * @param  list<string>  $entries
     */
    private function unlinkFiles(string $dir, array $entries): void
    {
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $dir.$entry;
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
    }

    /** The per-account directory above a staging dir, never the staging root itself. */
    private function removeParentIfEmpty(string $path): void
    {
        $parent = dirname(rtrim($path, '/'));
        if ($parent !== self::ROOT && is_dir($parent) && $this->isEmptyDirectory($parent)) {
            @rmdir($parent);
        }
    }

    private function assertInsideRoot(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        $root = self::ROOT;
        if ($normalized === $root || ! str_starts_with($normalized, $root.'/')) {
            throw new \RuntimeException('Invalid staging path: '.$path);
        }
        if (str_contains($normalized, '..')) {
            throw new \RuntimeException('Invalid staging path: '.$path);
        }

        return $normalized;
    }

    private function isEmptyDirectory(string $path): bool
    {
        $entries = scandir($path);

        return $entries !== false && count($entries) === 2;
    }
}
