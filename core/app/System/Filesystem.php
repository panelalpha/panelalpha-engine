<?php

namespace App\System;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Host filesystem operations.
 *
 * @psalm-type FilesystemInfo = array{
 *   filesystem: string,
 *   1024-blocks: int,
 *   used: int,
 *   available: int,
 *   capacity: int,
 *   mount_point: string
 * }
 */
class Filesystem
{
    /** @psalm-var ?FilesystemInfo */
    private static ?array $homeFilesystemInfo = null;

    private static ?string $homeFilesystemParentBlockDevice = null;

    public function __construct(
        private ProcessRunner $system,
    ) {}

    /** Copies with argv, not a shell string, since $target is often a path a caller chose. */
    public function copyFile(string $source, string $target, ?string $chown = null, ?string $chmod = null): void
    {
        $this->makeDirWithParents(dirname($target), $chown);
        $this->system->exec(['sudo', 'cp', $source, $target]);
        $this->setOwnerAndMode($target, $chown, $chmod);
    }

    /**
     * Write $contents at $path without ever opening what is already there: a
     * temp file next to $path, then `mv -T` over it, so a symlink at $path
     * (one a tenant could plant) is replaced, never followed or entered.
     */
    public function writeFileReplacingPath(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
    {
        $dir = dirname($path);
        $this->makeDirWithParents($dir, $chown);
        $tmp = $path.'.tmp-'.bin2hex(random_bytes(8));
        $this->copyContents($contents, $tmp);
        $this->setOwnerAndMode($tmp, $chown, $chmod);
        $this->system->exec(['sudo', 'mv', '-T', $tmp, $path]);
    }

    /**
     * Overwrite $path in place, keeping its inode, unless it is a symlink. For a
     * file a running container bind-mounts, where a rename would stay invisible
     * until the container is recreated.
     *
     * @return bool whether it wrote
     */
    public function overwriteFileUnlessSymlink(string $path, string $contents): bool
    {
        if ($this->isSymlink($path)) {
            return false;
        }
        $this->copyContents($contents, $path);

        return true;
    }

    public function assertNotSymlink(string $path): void
    {
        if ($this->isSymlink($path)) {
            throw new \RuntimeException("Refusing to rewrite '{$path}': it is a symlink.");
        }
    }

    /** Stage $contents in a local temp file and `sudo cp` it to $target; the temp file is removed either way. */
    private function copyContents(string $contents, string $target): void
    {
        $local = tempnam(sys_get_temp_dir(), 'tmp_');
        try {
            file_put_contents($local, $contents);
            $this->system->exec(['sudo', 'cp', $local, $target]);
        } finally {
            @unlink($local);
        }
    }

    private function setOwnerAndMode(string $path, ?string $chown, ?string $chmod): void
    {
        if ($chown) {
            $this->system->exec(['sudo', 'chown', $chown, $path]);
        }
        if ($chmod) {
            $this->system->exec(['sudo', 'chmod', $chmod, $path]);
        }
    }

    public function isDir(string $target): bool
    {
        $process = $this->system->runProcess(['sudo', 'test', '-d', $target]);

        return $process->getExitCode() === 0;
    }

    public function makeDirWithParents(string $target, ?string $chown = null): void
    {
        foreach (array_reverse($this->missingDirs($target)) as $d) {
            $this->system->exec(['sudo', 'mkdir', $d]);
            if ($chown) {
                $this->system->exec(['sudo', 'chown', $chown, $d]);
            }
        }
    }

    /**
     * $target and each missing parent, deepest first. Stops with an error at
     * the root, where dirname('/') is '/' and a failing `sudo test` would loop forever.
     *
     * @return list<string>
     */
    private function missingDirs(string $target): array
    {
        $missing = [];
        $dir = $target;
        while (! $this->isDir($dir)) {
            if (dirname($dir) === $dir) {
                throw new \RuntimeException("Cannot create {$target}: '{$dir}' does not read as a directory (is sudo working?)");
            }
            $missing[] = $dir;
            $dir = dirname($dir);
        }

        return $missing;
    }

    public function makeFileFromTemplate(string $filePath, string $templatePath, array $templateVars, ?string $chown = null, ?string $chmod = null): void
    {
        $content = file_get_contents($templatePath);
        $rendered = Blade::render($content, $templateVars);
        $tmpFile = tempnam(sys_get_temp_dir(), 'tmp_');
        file_put_contents($tmpFile, $rendered);
        $this->copyFile($tmpFile, $filePath, $chown, $chmod);
        unlink($tmpFile);
    }

    /**
     * @param  array<string>  $exclude
     */
    public function makeDirFromTemplate(string $dir, string $templateDir, array $templateVars, ?string $chown = null, ?string $chmod = null, array $exclude = []): void
    {
        [$copy, $render] = $this->templateTargets($dir, $templateDir, $exclude);

        foreach ($copy as $source => $target) {
            $this->copyFile($source, $target, $chown);
        }

        foreach ($render as $template => $target) {
            $this->makeFileFromTemplate(
                $target,
                $template,
                $templateVars,
                $chown,
                $chmod,
            );
        }
    }

    /**
     * Where each template file lands under $dir: plain files are copied, and
     * `.blade.php` files rendered to the name without that suffix.
     *
     * @param  array<string>  $exclude  paths relative to $dir
     * @return array{0: array<string, string>, 1: array<string, string>} copy and render, source => target
     */
    private function templateTargets(string $dir, string $templateDir, array $exclude): array
    {
        $excludeFullPaths = [];
        foreach ($exclude as $relPath) {
            $excludeFullPaths[] = "{$dir}/{$relPath}";
        }
        $copy = [];
        $render = [];

        foreach (File::allFiles($templateDir, true) as $file) {
            $filename = $file->getFilename();
            $relPath = $file->getRelativePath();
            $source = "{$file->getPath()}/{$filename}";
            $target = $dir.($relPath ? "/{$relPath}" : '')."/{$filename}";

            if (in_array($target, $excludeFullPaths)) {
                continue;
            }
            if (Str::endsWith($filename, '.blade.php')) {
                $render[$source] = Str::beforeLast($target, '.blade.php');

                continue;
            }
            $copy[$source] = $target;
        }

        return [$copy, $render];
    }

    /** The temporary copy often holds a secret, so it is removed even when the copy fails. */
    public function fileGetContents(string $path): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'tmp_');

        try {
            $this->system->exec(['sudo', 'cp', $path, $tmpFile]);

            return (string) file_get_contents($tmpFile);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'tmp_');
        file_put_contents($tmpFile, $contents);

        try {
            $this->copyFile($tmpFile, $path, $chown, $chmod);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function fileExists(string $path): bool
    {
        $response = $this->system->runProcess([
            'sudo',
            'test',
            '-f',
            $path,
        ]);

        return $response->getExitCode() === 0;
    }

    public function isSymlink(string $path): bool
    {
        return $this->system->runProcess(['sudo', 'test', '-L', $path])->getExitCode() === 0;
    }

    public function directoryExists(string $path): bool
    {
        $response = $this->system->runProcess([
            'sudo',
            'test',
            '-d',
            $path,
        ]);

        return $response->getExitCode() === 0;
    }

    /**
     * @return array<string>
     */
    public function ls(string $path): array
    {
        $response = $this->system->exec([
            'sudo',
            'ls',
            '-A1',
            $path,
        ]);
        $files = explode("\n", trim($response));

        return $files;
    }

    public function mtime(string $path): ?int
    {
        $process = $this->system->runProcess([
            'sudo',
            'stat',
            '-c',
            '%Y',
            $path,
        ]);

        return is_numeric($process->getOutput()) ? (int) $process->getOutput() : null;
    }

    /** Permission bits of $path, or null when there is no such file. */
    public function mode(string $path): ?int
    {
        $process = $this->system->runProcess([
            'sudo',
            'stat',
            '-c',
            '%a',
            $path,
        ]);
        $mode = trim($process->getOutput());

        return $process->getExitCode() === 0 && preg_match('/\A[0-7]{3,4}\z/', $mode) === 1 ? (int) octdec($mode) : null;
    }

    public function cat(string $path): ?string
    {
        return $this->outputOrNull(['sudo', 'cat', $path]);
    }

    public function tail(string $path, int $lines): ?string
    {
        return $this->outputOrNull(['sudo', 'tail', '-n', (string) $lines, $path]);
    }

    /** @param list<string> $argv */
    private function outputOrNull(array $argv): ?string
    {
        $process = $this->system->runProcess($argv);

        return $process->getExitCode() === 0 ? $process->getOutput() : null;
    }

    /**
     * @psalm-return FilesystemInfo
     */
    public function getHomeFilesystemInfo(): array
    {
        if (self::$homeFilesystemInfo === null) {
            $output = $this->system->execOnHost('df -P /home | tail -1');
            $info = preg_split('/\s+/', trim($output));

            self::$homeFilesystemInfo = [
                'filesystem' => $info[0],
                '1024-blocks' => (int) $info[1],
                'used' => (int) $info[2],
                'available' => (int) $info[3],
                'capacity' => (int) $info[4],
                'mount_point' => $info[5],
            ];
        }

        return self::$homeFilesystemInfo;
    }

    public function getHomeFilesystemMountPoint(): string
    {
        return $this->getHomeFilesystemInfo()['mount_point'];
    }

    /**
     * The block device an I/O limit throttles. Throws for a filesystem that is
     * not a block device (zfs) rather than render a compose file that cannot start.
     */
    public function getHomeFilesystemParentBlockDevice(): string
    {
        if (self::$homeFilesystemParentBlockDevice === null) {
            $fs = $this->getHomeFilesystemInfo()['filesystem'];
            $device = $this->resolveBlockDevice($fs);
            if ($device === null) {
                throw new \RuntimeException(
                    "The /home filesystem's block device could not be found (df reports '{$fs}'), "
                    .'so an I/O limit cannot be set. Remove device_read_bps/device_write_bps for it.'
                );
            }
            self::$homeFilesystemParentBlockDevice = $device;
        }

        return self::$homeFilesystemParentBlockDevice;
    }

    /**
     * Its parent device, the device itself for a whole disk, or null for no
     * block device (df reports a name, not a device, for zfs, btrfs and tmpfs).
     */
    private function resolveBlockDevice(string $filesystem): ?string
    {
        if (! str_starts_with($filesystem, '/dev/')) {
            return null;
        }
        $parent = trim($this->system->execOnHost(['lsblk', '-no', 'PKNAME', $filesystem]));
        if ($parent !== '') {
            return '/dev/'.$parent;
        }
        $type = trim($this->system->execOnHost(['lsblk', '-no', 'TYPE', $filesystem]));

        return $type !== '' ? '/dev/'.basename($filesystem) : null;
    }

    /**
     * Ensure the staging filesystem has at least $bytes free.
     * Prefer /var/tmp (backup staging), then /home.
     */
    public function assertFreeSpace(int $bytes): void
    {
        $freeBytes = $this->freeBytesOnStagingFilesystem();
        if ($freeBytes < $bytes) {
            throw new \RuntimeException(
                sprintf('Insufficient free space: need %d bytes, have %d bytes', $bytes, $freeBytes),
            );
        }
    }

    private function freeBytesOnStagingFilesystem(): int
    {
        foreach (['/var/tmp', '/home'] as $path) {
            $freeBytes = $this->parseDfAvailableBytes($path);
            if ($freeBytes !== null) {
                return $freeBytes;
            }
        }

        throw new \RuntimeException('Could not determine free space on staging filesystem');
    }

    private function parseDfAvailableBytes(string $path): ?int
    {
        try {
            $output = $this->system->execOnHost(['df', '-P', $path]);
        } catch (\Throwable) {
            return null;
        }

        $lines = array_values(array_filter(explode("\n", trim($output))));
        if (count($lines) < 2) {
            return null;
        }

        $fields = preg_split('/\s+/', trim($lines[1]));
        if ($fields === false || ! isset($fields[3]) || ! ctype_digit($fields[3])) {
            return null;
        }

        return (int) $fields[3] * 1024;
    }
}
