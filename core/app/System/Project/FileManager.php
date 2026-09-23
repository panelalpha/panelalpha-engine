<?php

namespace App\System\Project;

use App\System\Project as UserProject;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class FileManager
{
    public function __construct(
        private readonly UserProject $project,
    ) {
    }

    public function homeDirPath(): string
    {
        return $this->project->homeDirPath();
    }

    public function storage(): Filesystem
    {
        return Storage::build([
            'driver' => 'local',
            'root' => $this->homeDirPath(),
            'throw' => true,
        ]);
    }

    /**
     * Resolve a caller-supplied path into an absolute path under this Project's home.
     *
     * @throws ValidationException
     */
    public function resolvePath(string $path): string
    {
        if (Str::startsWith($path, '/var/www')) {
            $legacyHome = $this->project->model()->getHomeDir() ?? $this->homeDirPath();
            $path = Str::replaceFirst('/var/www', $legacyHome, $path);
        }
        $absolute = Str::startsWith($path, '/');
        $dir = Str::endsWith($path, '/');
        $clean = self::sanitizePath($path);
        if ($clean === false) {
            throw ValidationException::withMessages([
                'Invalid path',
            ]);
        }
        $home = $this->homeDirPath();
        if ($absolute && Str::startsWith('/' . $clean, $home)) {
            $clean = Str::after('/' . $clean, $home);
        }

        return $home . '/' . ltrim($clean, '/') . ($dir ? '/' : '');
    }

    public function putContents(string $path, string $contents): void
    {
        $path = $this->resolvePath($path);
        $model = $this->project->model();
        $chown = $model->getChownString();
        if ($chown === null) {
            Log::warning("Cannot put file contents as user {$this->project->username()}, UID/GID is missing", [
                'path' => $path,
            ]);
        }
        $this->project->system()->filesystem()->filePutContents($path, $contents, $chown, '644');
    }

    public function mkdir(string $path, bool $parents = false): void
    {
        $path = $this->resolvePath($path);
        $system = $this->project->system();
        $filesystem = $system->filesystem();
        $chown = $this->project->model()->getChownString() ?? '33:33';
        if ($parents) {
            $filesystem->makeDirWithParents($path, $chown);

            return;
        }
        $system->exec(['sudo', 'mkdir', $path]);
        $system->exec(['sudo', 'chown', $chown, $path]);
    }

    public function moveUploadedFile(string $path, UploadedFile $file): void
    {
        $path = $this->resolvePath($path);
        $targetDir = rtrim($path, '/');
        $targetFilename = $file->getClientOriginalName();
        $target = $targetDir . '/' . $targetFilename;

        $system = $this->project->system();
        $uidgid = $this->project->model()->getChownString() ?? '33:33';

        if (!is_dir($targetDir)) {
            $system->filesystem()->makeDirWithParents($targetDir, $uidgid);
        }

        $system->runProcess([
            'sudo',
            'sh',
            '-c',
            sprintf(
                'mv %s %s && chmod 644 %s && chown %s %s',
                escapeshellarg($file->path()),
                escapeshellarg($target),
                escapeshellarg($target),
                escapeshellarg($uidgid),
                escapeshellarg($target),
            ),
        ]);
    }

    public function exists(string $path): bool
    {
        $path = $this->resolvePath($path);
        $process = $this->runOnCore(['test', '-e', $path]);

        return $process->getExitCode() === 0;
    }

    /**
     * @return array<string, string>
     */
    public function stat(string $path): array
    {
        $path = $this->resolvePath($path);
        $process = $this->runOnCore([
            'stat',
            '--printf=%n %s %u %g %X %Y %Z %W',
            $path,
        ]);
        $this->assertSucceeded($process);

        $result = [];
        [
            $result['file_name'],
            $result['size'],
            $result['user_id'],
            $result['group_id'],
            $result['access_time'],
            $result['modify_time'],
            $result['status_change_time'],
            $result['create_time'],
        ] = explode(' ', $process->getOutput());

        return $result;
    }

    public function mv(string $sourcePath, string $destPath): void
    {
        $sourcePath = $this->resolvePath($sourcePath);
        $destPath = $this->resolvePath($destPath);
        $this->assertSucceeded($this->runOnCore([
            'mv',
            $sourcePath,
            $destPath,
        ]));
    }

    public function cp(string $sourcePath, string $destPath): void
    {
        $sourcePath = $this->resolvePath($sourcePath);
        $destPath = $this->resolvePath($destPath);
        $this->assertSucceeded($this->runOnCore([
            'cp',
            '-a',
            $sourcePath,
            $destPath,
        ]));
    }

    public function zip(
        string $zipPath,
        string $path,
        bool $skipParents = false,
        ?int $compressionLevel = null,
        ?string $fromDate = null,
        bool $ignoreEmpty = false,
    ): void {
        $zipPath = $this->resolvePath($zipPath);
        $path = $this->resolvePath($path);

        $workdir = null;
        if ($skipParents) {
            $workdir = dirname($path);
            $path = basename($path);
        }

        $recurse = $compressionLevel === null ? '-r' : '-r' . $compressionLevel;
        $command = ['zip', $recurse];
        if (is_string($fromDate) && $fromDate !== '') {
            $command[] = '--from-date';
            $command[] = $fromDate;
        }
        $command[] = $zipPath;
        $command[] = $path;

        $allowed = [0];
        if ($ignoreEmpty) {
            // zip exits 12 when there is nothing to archive, and 18 when some
            // names were missing or unreadable.
            $allowed[] = 12;
            $allowed[] = 18;
        }
        $this->assertSucceeded($this->runOnCore($command, $workdir), $allowed);
    }

    public function unzip(string $zipPath, string $path): void
    {
        $zipPath = $this->resolvePath($zipPath);
        $path = $this->resolvePath($path);
        $filename = basename($zipPath);

        if (Str::endsWith($filename, '.zip')) {
            $process = $this->runOnCore([
                'unzip',
                '-UU',
                '-o',
                $zipPath,
                '-d',
                $path,
            ]);
        } elseif (Str::endsWith($filename, '.tar.gz')) {
            $process = $this->runOnCore([
                'tar',
                '-zxvf',
                $zipPath,
                '-C',
                $path,
            ]);
        } else {
            $path = rtrim($path, '/');
            if ($zipPath != "{$path}/{$filename}") {
                $this->cp($zipPath, $path);
            }
            $process = $this->runOnCore([
                'gunzip',
                '-f',
                $filename,
            ], $path);
        }
        $this->assertSucceeded($process);
    }

    public function remove(string $path, bool $recursive = false): void
    {
        $path = $this->resolvePath($path);
        $command = ['rm', '-f'];
        if ($recursive) {
            $command[] = '-r';
        }
        $command[] = $path;

        $this->assertSucceeded($this->runOnCore($command));
    }

    public function diskUsage(string $directory = '/'): int
    {
        $path = $this->resolvePath($directory);
        // ~/docker is the DinD data-root: root-owned 0700, unreadable here, and
        // engine images and build cache rather than the project's own files.
        $dataRoot = rtrim($this->homeDirPath(), '/') . '/docker';
        $process = $this->runOnCore(['du', '-shm', '--exclude=' . $dataRoot, $path]);
        $this->assertSucceeded($process);
        $mb = Str::before($process->getOutput(), "\t");

        return (int) $mb;
    }

    public function moveDirectoryContents(string $source, string $dest, bool $override = true): void
    {
        $sourceDir = rtrim($this->resolvePath($source), '/');
        $destDir = rtrim($this->resolvePath($dest), '/');
        if (!is_dir($destDir)) {
            throw new \Exception('Destination directory does not exist');
        }

        $this->assertSucceeded($this->runOnCore([
            'find',
            $sourceDir,
            '-mindepth',
            '1',
            '-maxdepth',
            '1',
            '-exec',
            'mv',
            $override ? '--force' : '--no-clobber',
            '-t',
            $destDir,
            '--',
            '{}',
            '+',
        ]));
    }

    public function fetch(string $url, string $destinationDir, ?string $filename = null): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \Exception('Only http and https URLs can be fetched');
        }

        $filename ??= $this->filenameFromUrl($url);
        if ($this->filenameIsUnsafe($filename)) {
            throw new \Exception('Invalid filename');
        }

        $dir = rtrim($this->resolvePath($destinationDir), '/');
        if (!is_dir($dir)) {
            throw new \Exception('Destination directory does not exist');
        }

        $this->assertSucceeded($this->runOnCore([
            'curl',
            '-fSL',
            $url,
            '-o',
            $dir . '/' . $filename,
        ]));
    }

    public function chmod(string $path, string $mode): void
    {
        if (!preg_match('/\A[0-7]{3,4}\z/', $mode)) {
            throw new \Exception('Invalid mode');
        }

        $path = $this->resolvePath($path);
        $this->assertSucceeded($this->runOnCore(['chmod', $mode, $path]));
    }

    /**
     * Run a file command in the core container, as the project user.
     *
     * The account is named by uid and gid. Its passwd entry lives on the
     * machine, so `sudo -u` cannot see it from this container. `setpriv`
     * drops to the ids directly, the same way archive extraction does.
     *
     * @param list<string> $command
     */
    private function runOnCore(array $command, ?string $workdir = null): Process
    {
        $model = $this->project->model();
        $uid = $model->getUid() ?? 33;
        $gid = $model->getGid() ?? 33;
        $argv = [
            'sudo',
            'setpriv',
            '--reuid',
            (string) $uid,
            '--regid',
            (string) $gid,
            '--clear-groups',
        ];
        if ($workdir !== null) {
            if (!is_dir($workdir)) {
                throw new \Exception('Invalid path');
            }
            $argv[] = 'env';
            $argv[] = '--chdir=' . $workdir;
        }

        return $this->project->system()->runProcess([...$argv, ...$command]);
    }

    /**
     * @param list<int> $allowedExitCodes
     */
    private function assertSucceeded(Process $process, array $allowedExitCodes = [0]): void
    {
        $code = $process->getExitCode();
        if (in_array($code, $allowedExitCodes, true)) {
            return;
        }

        $message = ($process->getErrorOutput() ?: $process->getOutput()) . " (exit code {$code})";
        throw new \Exception($message);
    }

    private function filenameFromUrl(string $url): string
    {
        $filename = basename((string) parse_url($url, PHP_URL_PATH));
        if ($filename === '' || $filename === '/' || $filename === '.' || $filename === '..') {
            throw new \Exception('Could not determine destination filename from URL; provide filename explicitly');
        }

        return $filename;
    }

    private function filenameIsUnsafe(string $filename): bool
    {
        return $filename === ''
            || $filename === '.'
            || $filename === '..'
            || str_contains($filename, '/')
            || str_contains($filename, '\\');
    }

    /**
     * @return string|false
     */
    private static function sanitizePath(string $path): string|false
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return false;
        }
        $validParts = [];
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if (count($validParts) < 1) {
                    return false;
                }
                array_pop($validParts);
                continue;
            }
            $validParts[] = $part;
        }

        return implode('/', $validParts);
    }
}
