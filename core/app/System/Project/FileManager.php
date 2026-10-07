<?php

namespace App\System\Project;

use App\Exceptions\NotFoundException;
use App\Lib\Deploy\Source\ArchiveSafety;
use App\Lib\Deploy\Source\ArchiveUnpackedSize;
use App\System\Project as UserProject;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class FileManager
{
    /** Beside the deploy's own staging area, and just as root-owned. */
    private const UNZIP_STAGE_DIR = '/var/lib/panelalpha/unzip-stage';

    /** `sh -c` body for assertInsideHome(): $1 the home, then F:<path> / E:<entry>. */
    public const CONFINE_SCRIPT = 'home=$(realpath -- "$1") || exit 1; shift; for p; do case "$p" in '
        . 'F:*) r=$(realpath -m -- "${p#F:}") ;; '
        . '*) q=${p#E:}; q=${q%/}; r=$(realpath -m -- "$(dirname -- "$q")")/$(basename -- "$q") ;; esac; '
        . 'case "$r" in "$home"|"$home"/*) ;; '
        . '*) echo "Refusing ${p#?:}: it resolves outside the project home" >&2; exit 3 ;; esac; done';

    /** `sh -c` body for entryType(): $1 the path, $2 the same without its trailing slash. */
    public const ENTRY_TYPE_SCRIPT = 'if [ ! -e "$1" ]; then if [ -L "$1" ]; then echo dangling; else echo missing; fi; '
        . 'elif [ -d "$1" ]; then if [ -L "$2" ]; then echo dir-link; else echo dir; fi; '
        . 'else echo file; fi';

    public function __construct(
        private readonly UserProject $project,
        private readonly string $unzipStageRoot = self::UNZIP_STAGE_DIR,
        private readonly int $unzipMaxBytes = ArchiveSafety::MAX_UNCOMPRESSED_BYTES,
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

    /**
     * Written as the account, like every other operation here. As root, a
     * symlink the account planted (`~/x -> /etc/cron.d/x`, or into another
     * account's home) was followed and the file written and chowned there.
     */
    public function putContents(string $path, string $contents): void
    {
        $path = $this->resolvePath($path);
        $staged = tempnam(sys_get_temp_dir(), 'pa-put-');
        if ($staged === false) {
            throw new \RuntimeException('Cannot stage the file contents');
        }
        try {
            file_put_contents($staged, $contents);
            $this->writeAsUser($staged, $path);
        } finally {
            @unlink($staged);
        }
    }

    public function mkdir(string $path, bool $parents = false): void
    {
        $path = $this->resolvePath($path);
        $this->assertInsideHome([$path]);
        $this->assertSucceeded($this->runOnCore($parents ? ['mkdir', '-p', '--', $path] : ['mkdir', '--', $path]));
    }

    public function moveUploadedFile(string $path, UploadedFile $file): void
    {
        $path = $this->resolvePath($path);
        $targetDir = rtrim($path, '/');
        $target = $targetDir . '/' . $file->getClientOriginalName();

        $this->writeAsUser($file->path(), $target);
    }

    /**
     * Copy an engine-side file to $target as the account, creating its parent
     * directories as root used to. The source is made readable for that: it
     * is in the engine's own temp directory, which no account can reach.
     */
    private function writeAsUser(string $source, string $target): void
    {
        $this->assertInsideHome([$target]);
        @chmod($source, 0644);
        $this->assertSucceeded($this->runOnCore([
            'sh', '-c', 'mkdir -p -- "$(dirname -- "$2")" && cat -- "$1" > "$2" && chmod 644 -- "$2"',
            'sh', $source, $target,
        ]));
    }

    public function exists(string $path): bool
    {
        $path = $this->resolvePath($path);
        // Every link followed, the last one too: test -e answers for the target.
        $this->assertInsideHome([$path]);
        $process = $this->runOnCore(['test', '-e', $path]);

        return $process->getExitCode() === 0;
    }

    /** A regular file, links followed, as the account sees it. */
    public function isFile(string $path): bool
    {
        return $this->runOnCore(['test', '-f', $this->resolvePath($path)])->getExitCode() === 0;
    }

    /**
     * @return array<string, string>
     */
    public function stat(string $path): array
    {
        $path = $this->resolvePath($path);
        // Like exists(): a link out of the home is refused, not described.
        $this->assertInsideHome([$path]);
        if (in_array($this->entryType($path), ['missing', 'dangling'], true)) {
            throw new NotFoundException("No such file or directory: {$path}");
        }
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
        $this->assertInsideHome([$destPath], [$sourcePath]);
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
        $this->assertInsideHome([$destPath], [$sourcePath]);
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
        $this->assertInsideHome([$zipPath, $path]);

        $workdir = null;
        if ($skipParents) {
            $workdir = dirname($path);
            $path = basename($path);
            if (!$this->isDirectory($workdir)) {
                throw new \Exception('Invalid path');
            }
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
        // The archive too: it is copied to the stage as root.
        $this->assertInsideHome([$zipPath, $path]);
        $filename = basename($zipPath);
        $isZip = Str::endsWith($filename, '.zip');

        // The size is counted off a root-owned read-only copy, and that copy is
        // what gets extracted: the account can rewrite its own file between a
        // check and an unpack. Without this a 6 MB zip wrote 6 GB here while
        // the deploy path refused the same archive.
        $system = $this->project->system();
        $stageDir = $this->unzipStageRoot . '/' . bin2hex(random_bytes(8));
        $staged = $stageDir . '/' . $filename;
        try {
            $system->exec(['sudo', 'mkdir', '-p', $stageDir]);
            $system->exec(['sudo', 'chmod', '0755', $stageDir]);
            $system->exec(['sudo', 'cp', '--no-dereference', $zipPath, $staged]);
            $system->exec(['sudo', 'chmod', '0444', $staged]);
            try {
                ArchiveUnpackedSize::assertWithin($staged, $isZip, $this->unzipMaxBytes);
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                throw ValidationException::withMessages(['zip_path' => $e->getMessage()]);
            }

            if ($isZip) {
                $process = $this->runOnCore(['unzip', '-UU', '-o', $staged, '-d', $path]);
            } elseif (Str::endsWith($filename, '.tar.gz')) {
                $process = $this->runOnCore(['tar', '-zxvf', $staged, '-C', $path]);
            } else {
                // gunzip's own result: `x.gz` becomes `x` in the target
                // directory, and a `.gz` that was already there is replaced.
                $path = rtrim($path, '/');
                $process = $this->runOnCore([
                    'sh', '-c', 'gzip -dc -- "$1" > "$2"', 'sh', $staged, $path . '/' . Str::beforeLast($filename, '.gz'),
                ]);
                if ($process->isSuccessful() && $zipPath === "{$path}/{$filename}") {
                    $this->runOnCore(['rm', '-f', $zipPath]);
                }
            }
            $this->assertSucceeded($process);
        } finally {
            $system->runProcess(['sudo', 'rm', '-rf', $stageDir]);
        }
    }

    public function remove(string $path, bool $recursive = false): void
    {
        $path = $this->resolvePath($path);
        // The entry itself may be a link pointing anywhere: rm removes the link.
        $this->assertInsideHome([], [$path]);
        // rm -f is silent about a missing path. A link whose target is gone is still there to remove.
        $type = $this->entryType($path);
        if ($type === 'missing') {
            throw new NotFoundException("No such file or directory: {$path}");
        }
        if ($type === 'dir' && !$recursive) {
            // Otherwise rm answers a bare "Is a directory".
            throw ValidationException::withMessages([
                'recursive' => 'The path is a directory. Set recursive to true to delete it and everything in it.',
            ]);
        }
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

    /**
     * Bytes the inner Docker's json-file container logs take, rotated files
     * included. They sit in ~/docker, which diskUsage() leaves out but the
     * quota does not; root-owned, so read as root, and find follows no link.
     */
    public function containerLogBytes(): int
    {
        $dir = rtrim($this->homeDirPath(), '/') . '/docker/containers';
        $process = $this->project->system()->runProcess([
            'sudo', 'find', $dir, '-mindepth', '2', '-maxdepth', '2', '-type', 'f', '-name', '*-json.log*', '-printf', '%s\n',
        ]);
        // No containers directory yet means no logs.
        $bytes = 0;
        foreach (explode("\n", $process->getOutput()) as $line) {
            if (ctype_digit($line)) {
                $bytes += (int) $line;
            }
        }

        return $bytes;
    }

    public function moveDirectoryContents(string $source, string $dest, bool $override = true): void
    {
        $sourceDir = rtrim($this->resolvePath($source), '/');
        $destDir = rtrim($this->resolvePath($dest), '/');
        $this->assertInsideHome([$sourceDir, $destDir]);
        if (!$this->isDirectory($destDir)) {
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
        $this->assertInsideHome([$dir . '/' . $filename]);
        if (!$this->isDirectory($dir)) {
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
        $this->assertInsideHome([$path]);
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
        if ($workdir !== null) {
            $command = ['env', '--chdir=' . $workdir, ...$command];
        }

        return $this->project->system()->runProcess($this->asAccount($command));
    }

    /**
     * $command run as the account, by uid and gid.
     *
     * @param list<string> $command
     * @return list<string>
     */
    public function asAccount(array $command): array
    {
        $model = $this->project->model();

        return [
            'sudo',
            'setpriv',
            '--reuid',
            (string) ($model->getUid() ?? 33),
            '--regid',
            (string) ($model->getGid() ?? 33),
            '--clear-groups',
            ...$command,
        ];
    }

    /**
     * Refuse a path that leaves the home once symlinks are followed. The
     * commands run as the account, but in the engine's container: a link the
     * account planted (`~/x -> /tmp/x`) took a write anywhere that uid could
     * write there. Checked as the account, so the check sees what it sees.
     *
     * @param list<string> $paths every symlink followed, the last one too
     * @param list<string> $entries the last component is the entry itself
     *                              (what rm removes, what mv and cp take)
     */
    private function assertInsideHome(array $paths, array $entries = []): void
    {
        $args = [
            ...array_map(fn (string $p): string => 'F:' . $p, $paths),
            ...array_map(fn (string $p): string => 'E:' . $p, $entries),
        ];
        $process = $this->runOnCore([
            'sh', '-c', self::CONFINE_SCRIPT, 'sh', $this->homeDirPath(), ...$args,
        ]);
        $this->assertSucceeded($process);
    }

    /**
     * What is at $path, asked as the account like the command it guards: the
     * engine's PHP is another user and cannot enter the account's private directories.
     *
     * @return 'missing'|'dangling'|'dir'|'dir-link'|'file'
     */
    private function entryType(string $path): string
    {
        $process = $this->runOnCore(['sh', '-c', self::ENTRY_TYPE_SCRIPT, 'sh', $path, rtrim($path, '/')]);
        $this->assertSucceeded($process);
        $type = trim($process->getOutput());

        return in_array($type, ['missing', 'dangling', 'dir', 'dir-link', 'file'], true)
            ? $type
            : throw new \RuntimeException("Cannot tell what {$path} is: {$type}");
    }

    private function isDirectory(string $path): bool
    {
        return in_array($this->entryType($path), ['dir', 'dir-link'], true);
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
