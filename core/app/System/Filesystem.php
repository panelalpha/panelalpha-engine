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
    ) {
    }

    public function copyFile(string $source, string $target, ?string $chown = null, ?string $chmod = null): void
    {
        $this->makeDirWithParents(dirname($target), $chown);
        // argv, not a shell string: $target is often a path a caller chose.
        $this->system->exec(['sudo', 'cp', $source, $target]);
        if ($chown) {
            $this->system->exec(['sudo', 'chown', $chown, $target]);
        }
        if ($chmod) {
            $this->system->exec(['sudo', 'chmod', $chmod, $target]);
        }
    }

    public function isDir(string $target): bool
    {
        $process = $this->system->runProcess(["sudo", "test", "-d", $target]);
        return $process->getExitCode() === 0;
    }

    public function makeDirWithParents(string $target, ?string $chown = null): void
    {
        $toCreate = [];
        $dir = $target;
        while (!$this->isDir($dir)) {
            // dirname('/') is '/': stop there, or a failing `sudo test` spins forever.
            if (dirname($dir) === $dir) {
                throw new \RuntimeException("Cannot create {$target}: '{$dir}' does not read as a directory (is sudo working?)");
            }
            $toCreate[] = $dir;
            $dir = dirname($dir);
        }
        for ($i = count($toCreate) - 1; $i >= 0; $i--) {
            $d = $toCreate[$i];
            $this->system->exec(["sudo", "mkdir", $d]);
            if ($chown) {
                $this->system->exec(["sudo", "chown", $chown, $d]);
            }
        }
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
     * @param array<string> $exclude
     */
    public function makeDirFromTemplate(string $dir, string $templateDir, array $templateVars, ?string $chown = null, ?string $chmod = null, array $exclude = []): void
    {
        $excludeFullPaths = [];
        foreach ($exclude as $relPath) {
            $excludeFullPaths[] = "{$dir}/{$relPath}";
        }
        $copy = [];
        $render = [];
        $files = File::allFiles($templateDir, true);

        foreach ($files as $file) {
            $filename = $file->getFilename();
            $path = $file->getPath();
            $relPath = $file->getRelativePath();
            $source = "{$path}/{$filename}";
            $target = $dir;
            if ($relPath) {
                $target .= "/{$relPath}";
            }
            $target .= "/{$filename}";

            if (in_array($target, $excludeFullPaths)) {
                continue;
            }
            if (Str::endsWith($filename, '.blade.php')) {
                $render[$source] = Str::beforeLast($target, '.blade.php');
                continue;
            }
            $copy[$source] = $target;
        }

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

    public function fileGetContents(string $path): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'tmp_');
        $this->system->exec(['sudo', 'cp', $path, $tmpFile]);
        $contents = file_get_contents($tmpFile);
        unlink($tmpFile);
        return $contents;
    }

    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'tmp_');
        file_put_contents($tmpFile, $contents);
        $this->copyFile($tmpFile, $path, $chown, $chmod);
        unlink($tmpFile);
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
        return is_numeric($process->getOutput()) ? (int)$process->getOutput() : null;
    }

    public function cat(string $path): ?string
    {
        $process = $this->system->runProcess([
            'sudo',
            'cat',
            $path,
        ]);
        if ($process->getExitCode() !== 0) {
            return null;
        }
        return $process->getOutput();
    }

    public function tail(string $path, int $lines): ?string
    {
        $process = $this->system->runProcess([
            'sudo',
            'tail',
            '-n',
            (string)$lines,
            $path,
        ]);
        if ($process->getExitCode() !== 0) {
            return null;
        }
        return $process->getOutput();
    }

    /**
     * @psalm-return FilesystemInfo
     */
    public function getHomeFilesystemInfo(): array
    {
        if (self::$homeFilesystemInfo === null) {
            $output = $this->system->execOnHost("df -P /home | tail -1");
            $info = preg_split('/\s+/', trim($output));

            self::$homeFilesystemInfo = [
                'filesystem' => $info[0],
                '1024-blocks' => (int)$info[1],
                'used' => (int)$info[2],
                'available' => (int)$info[3],
                'capacity' => (int)$info[4],
                'mount_point' => $info[5],
            ];
        }
        return self::$homeFilesystemInfo;
    }

    public function getHomeFilesystemMountPoint(): string
    {
        return $this->getHomeFilesystemInfo()['mount_point'];
    }

    public function getHomeFilesystemParentBlockDevice(): string
    {
        if (self::$homeFilesystemParentBlockDevice === null) {
            $fs = $this->getHomeFilesystemInfo()['filesystem'];
            $parent = $this->system->execOnHost(["lsblk", "-no", "PKNAME", $fs]);

            self::$homeFilesystemParentBlockDevice = '/dev/' . trim($parent);
        }
        return self::$homeFilesystemParentBlockDevice;
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
        if ($fields === false || !isset($fields[3]) || !ctype_digit($fields[3])) {
            return null;
        }

        return (int) $fields[3] * 1024;
    }
}
