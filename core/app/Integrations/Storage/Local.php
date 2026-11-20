<?php

namespace App\Integrations\Storage;

use App\Models\BackupContainer;
use App\System\Privileged;
use FilesystemIterator;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

final class Local extends AbstractStorage
{
    /** @var list<string> */
    private const CHOWN_BLOCKLIST = [
        '',
        '/',
        '/home',
        '/root',
        '/etc',
        '/usr',
        '/var',
        '/opt',
        '/boot',
        '/tmp',
    ];

    public function __construct(
        private readonly string $root
    ) {}

    public static function fromContainer(BackupContainer $container): self
    {
        return new self($container->location);
    }

    public static function releaseLocation(BackupContainer $container): void
    {
        self::fromContainer($container)->removeEmptyRoot();
    }

    public function put(string $key, $stream): void
    {
        $path = $this->resolveKey($key);
        $dir = dirname($path);
        $this->ensureWritableDirectory($dir);

        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Could not write backup object: {$key}");
        }

        try {
            stream_copy_to_stream($stream, $handle);
        } finally {
            fclose($handle);
        }
    }

    public function readStream(string $key)
    {
        $path = $this->resolveKey($key);
        if (! is_file($path)) {
            throw new RuntimeException("Backup object not found: {$key}");
        }

        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException("Could not read backup object: {$key}");
        }

        return $stream;
    }

    /** The absolute path an object key resolves to, for serving a local object as a file response. */
    public function path(string $key): string
    {
        return $this->resolveKey($key);
    }

    public function delete(string $key): void
    {
        $path = $this->resolveKey($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Removed as root: a backup written by the CLI is root-owned, and the web user
     * running the delete job could not remove it.
     */
    public function deletePrefix(string $prefix): void
    {
        $dir = $this->resolvePrefixDir($prefix);
        if (! is_dir($dir)) {
            return;
        }

        try {
            $this->asRoot(Privileged::backupStore()->removeTree($dir));
        } catch (RuntimeException $e) {
            throw new RuntimeException("Could not remove backup objects under {$prefix}: ".$e->getMessage(), 0, $e);
        }

        if (file_exists($dir)) {
            throw new RuntimeException("Could not remove backup objects under {$prefix}");
        }

        $this->removeEmptyParents($dir);
    }

    /**
     * Best effort, as root: the store's parent directory is usually root-owned. A root
     * holding anything but empty directories, or a failure, keeps it as it is.
     */
    public function removeEmptyRoot(): void
    {
        $root = $this->rootRealPath();
        if ($root === null || $this->holdsAnythingButDirectories($root)) {
            return;
        }

        try {
            $this->asRoot(Privileged::backupStore()->removeEmptyDirectories($root));
        } catch (RuntimeException|InvalidArgumentException $e) {
            Log::warning("Could not remove the empty backup storage root {$root}: ".$e->getMessage());
        }
    }

    public function exists(string $key): bool
    {
        $path = $this->resolveKey($key);

        return is_file($path);
    }

    public function size(string $key): int
    {
        $path = $this->resolveKey($key);
        if (! is_file($path)) {
            throw new RuntimeException("Backup object not found: {$key}");
        }

        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException("Could not read backup object size: {$key}");
        }

        return (int) $size;
    }

    public function test(): void
    {
        $this->ensureWritableDirectory(rtrim($this->root, '/'));
        $root = $this->rootRealPath();
        if ($root === null) {
            throw new RuntimeException("Backup storage root does not exist: {$this->root}");
        }
        if (! is_writable($root)) {
            throw new RuntimeException("Backup storage root is not writable: {$this->root}");
        }

        $probe = $root.'/'.self::PROBE_FILE;
        if (@file_put_contents($probe, 'ok') === false) {
            throw new RuntimeException("Backup storage root is not writable: {$this->root}");
        }
        @unlink($probe);
    }

    /**
     * As root, the directories a deleted prefix left empty on its way up, the storage root excluded.
     * Best effort: the objects are already gone, so a failure here only leaves empty directories.
     */
    private function removeEmptyParents(string $dir): void
    {
        $parents = $this->parentsBelowRoot($dir);
        if ($parents === []) {
            return;
        }

        try {
            $this->asRoot(Privileged::backupStore()->removeEmptyParents(...$parents));
        } catch (RuntimeException $e) {
            Log::warning("Could not remove the empty backup directories above {$dir}: ".$e->getMessage());
        }
    }

    /** @return list<string> deepest first */
    private function parentsBelowRoot(string $dir): array
    {
        $root = $this->rootRealPath();
        $parents = [];
        for ($parent = dirname($dir); $root !== null && $parent !== $root && self::isInside($parent, $root); $parent = dirname($parent)) {
            $parents[] = $parent;
        }

        return $parents;
    }

    /** A location that cannot be listed holds something this driver must not remove. */
    private function holdsAnythingButDirectories(string $root): bool
    {
        try {
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($entries as $entry) {
                if ($entry->isLink() || ! $entry->isDir()) {
                    return true;
                }
            }
        } catch (UnexpectedValueException) {
            return true;
        }

        return false;
    }

    private function rootRealPath(): ?string
    {
        $real = realpath($this->root);

        return $real === false ? null : $real;
    }

    private function resolvePrefixDir(string $prefix): string
    {
        $prefix = $this->normalizeRelativePath($prefix);
        if ($prefix === '') {
            throw new InvalidArgumentException('Prefix must name a directory below the storage root.');
        }

        $root = $this->rootRealPath();
        if ($root === null) {
            return rtrim($this->root, '/').'/'.$prefix;
        }

        $candidate = $root.'/'.$prefix;
        $dirReal = realpath($candidate);
        if ($dirReal === false) {
            return $candidate;
        }
        if (! self::isInside($dirReal, $root)) {
            throw new InvalidArgumentException('Prefix must stay inside the storage root.');
        }

        return $dirReal;
    }

    private function resolveKey(string $key): string
    {
        $relative = $this->normalizeRelativePath($key);
        if ($relative === '') {
            throw new InvalidArgumentException('Invalid key.');
        }

        $root = $this->rootRealPath();
        if ($root === null) {
            return rtrim($this->root, '/').'/'.$relative;
        }

        return $this->confinedToRoot($root.'/'.$relative, $root);
    }

    /**
     * The path with its existing parent resolved through symlinks, refused when that lands outside the root.
     */
    private function confinedToRoot(string $path, string $root): string
    {
        $parent = dirname($path);
        if (is_dir($parent)) {
            $parentReal = realpath($parent);
            if ($parentReal === false) {
                throw new InvalidArgumentException('Key must stay inside the storage root.');
            }
            $path = $parentReal.'/'.basename($path);
        }

        if (! self::isInside($path, $root)) {
            throw new InvalidArgumentException('Key must stay inside the storage root.');
        }

        return $path;
    }

    private static function isInside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root.'/');
    }

    private function ensureWritableDirectory(string $dir): void
    {
        if (is_dir($dir) && is_writable($dir)) {
            return;
        }

        if (str_contains($dir, '..')) {
            throw new InvalidArgumentException('Directory must stay inside the storage root.');
        }

        if (@mkdir($dir, 0775, true) || (is_dir($dir) && is_writable($dir))) {
            return;
        }

        try {
            $this->privilegedEnsureWritable($dir);
        } catch (RuntimeException $e) {
            throw new RuntimeException("Could not create backup storage directory {$dir}: ".$e->getMessage(), 0, $e);
        }

        if (! is_dir($dir) || ! is_writable($dir)) {
            throw new RuntimeException("Could not create backup storage directory: {$dir}");
        }
    }

    private function privilegedEnsureWritable(string $dir): void
    {
        $root = self::normalizedDir($this->root);
        $dir = self::normalizedDir($dir);
        if (! self::isInside($dir, $root)) {
            throw new RuntimeException("Refusing to create directory outside storage root: {$dir}");
        }

        $this->assertSafeToChown($root);
        $this->asRoot(Privileged::backupStore()->makeDirectories($dir));
        $this->handToWebUserUpTo($dir, $root);
    }

    /**
     * Gives www-data every directory from $dir up to the storage root, stopping short of `/`.
     */
    private function handToWebUserUpTo(string $dir, string $root): void
    {
        $cursor = $dir;
        while (true) {
            $this->assertSafeToChown($cursor);
            $this->asRoot(Privileged::backupStore()->giveToWebUser($cursor));
            $this->asRoot(Privileged::backupStore()->openToWebUser($cursor));
            if ($cursor === $root) {
                return;
            }
            $parent = dirname($cursor);
            if ($parent === $cursor || $parent === '/') {
                return;
            }
            $cursor = $parent;
        }
    }

    private function assertSafeToChown(string $path): void
    {
        $path = self::normalizedDir($path);
        if (in_array($path, self::CHOWN_BLOCKLIST, true)) {
            throw new RuntimeException("Refusing to change ownership of {$path}");
        }
    }

    private static function normalizedDir(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * @param  list<string>  $command
     */
    private function asRoot(array $command): void
    {
        $process = new Process($command);
        $process->setTimeout(60);
        $process->run();
        if ($process->isSuccessful()) {
            return;
        }

        $message = trim($process->getErrorOutput() ?: $process->getOutput());
        throw new RuntimeException($message !== '' ? $message : 'the root command failed');
    }
}
