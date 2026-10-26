<?php

namespace App\Lib\Deploy\Inspect;

/**
 * A source made readable: the directory to inspect, what it came from, and how
 * to put it back.
 *
 * `release()` matters. A git source is a real clone in a temp directory, and an
 * inspection endpoint that leaked one per call would fill the disk of a server
 * whose whole job is other people's disks. Callers run it in a finally block.
 */
final class ResolvedSource
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $type,
        public readonly string $reference,
        public readonly string $dir,
        public readonly array $meta = [],
        private readonly ?string $removeOnRelease = null
    ) {}

    /** Is the directory worth inspecting at all? */
    public function isEmpty(): bool
    {
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                return false;
            }
        }

        return true;
    }

    public function release(): void
    {
        if ($this->removeOnRelease === null) {
            return;
        }

        self::removeTree($this->removeOnRelease);
    }

    /**
     * Delete a directory and everything under it, following no symlink out of
     * it — a cloned repository can contain one pointing anywhere.
     */
    public static function removeTree(string $dir): void
    {
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir() && ! $file->isLink()) {
                @rmdir($file->getPathname());

                continue;
            }
            @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
