<?php

namespace App\System\Project;

use App\Exceptions\NotFoundException;
use App\Exceptions\ProblemException;
use App\Lib\Deploy\Source\ArchiveSafety;
use App\Lib\Deploy\Source\ArchiveUnpackedSize;
use App\Lib\Deploy\Source\UploadedArchive;
use App\Lib\Http\OutboundUrl;
use App\System\Project as UserProject;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class FileManager
{
    /**
     * Root-owned, beside the account homes: on their filesystem, so an unpacked entry is renamed into
     * place, and outside every home, so no account's container can reach what is being unpacked.
     */
    private const UNZIP_STAGE_NAME = '.panelalpha-unzip-stage';

    /** Past this age a stage directory is a killed request's, not a live one's. */
    private const UNZIP_STALE_MINUTES = 1440;

    /** Largest download accepted from a URL, in bytes. */
    public const MAX_FETCH_BYTES = OutboundUrl::MAX_DOWNLOAD_BYTES;

    /** zip exits 12 when there is nothing to archive, and 18 when some names were missing or unreadable. */
    private const ZIP_EMPTY_EXIT_CODES = [12, 18];

    /** curl's exit code for --max-filesize, hit before the transfer or part way through it. */
    private const CURL_FILE_TOO_LARGE = 63;

    /** `sh -c` body for assertInsideHome(): $1 the home, then F:<path> / E:<entry>. */
    public const CONFINE_SCRIPT = 'home=$(realpath -- "$1") || exit 1; shift; for p; do case "$p" in '
        .'F:*) r=$(realpath -m -- "${p#F:}") ;; '
        .'*) q=${p#E:}; q=${q%/}; r=$(realpath -m -- "$(dirname -- "$q")")/$(basename -- "$q") ;; esac; '
        .'case "$r" in "$home"|"$home"/*) ;; '
        .'*) echo "Refusing ${p#?:}: it resolves outside the project home" >&2; exit 3 ;; esac; done';

    /** `sh -c` body for entryType(): $1 the path, $2 the same without its trailing slash. */
    public const ENTRY_TYPE_SCRIPT = 'if [ ! -e "$1" ]; then if [ -L "$1" ]; then echo dangling; else echo missing; fi; '
        .'elif [ -d "$1" ]; then if [ -L "$2" ]; then echo dir-link; else echo dir; fi; '
        .'else echo file; fi';

    /** One find row per entry: name, type, size, mtime, mode, uid, gid, link target, target type. */
    private const ENTRY_FIELDS = '%f\0%y\0%s\0%T@\0%m\0%U\0%G\0%l\0%Y\0';

    /** The confinement script's exit code for a path that resolves outside the home. */
    private const CONFINE_OUTSIDE_EXIT_CODE = 3;

    /**
     * `sh -c` body for moveUnpackedInto(): $1 the unpacked directory, $2 the home, $3 the destination.
     * The destination is entered once and checked by its real path; every rename is relative to it.
     */
    private const MOVE_UNPACKED_SCRIPT = 'src=$1; home=$(realpath -- "$2") || exit 1; '
        .'mkdir -p -- "$3" && cd -- "$3" || exit 1; case $(pwd -P) in "$home"|"$home"/*) ;; '
        .'*) echo "Refusing $3: it resolves outside the project home" >&2; exit 3 ;; esac; '
        .'set --; for e in "$src"/* "$src"/.[!.]* "$src"/..?*; do '
        .'if [ -e "$e" ] || [ -L "$e" ]; then set -- "$@" "${e##*/}"; fi; done; '
        .'taken=0; for n; do if [ -e "./$n" ] || [ -L "./$n" ]; then printf \'%s\\0\' "$n"; taken=1; fi; done; '
        .'[ "$taken" = 0 ] || exit 4; '
        .'for n; do mv -T -- "$src/$n" "./$n" || exit 1; done';

    /** The move script's exit code for entries the destination already holds. */
    private const MOVE_TAKEN_EXIT_CODE = 4;

    /** Names a refusal shows of the entries the destination already holds. */
    private const TAKEN_NAMES_SHOWN = 10;

    /** Entries one listing returns when the caller does not cap it. */
    public const LIST_DEFAULT_LIMIT = 5000;

    /** Most entries one listing may return. */
    public const LIST_MAX_LIMIT = 20000;

    public function __construct(
        private readonly UserProject $project,
        private readonly ?string $unzipStageRoot = null,
        private readonly int $unzipMaxBytes = ArchiveSafety::MAX_UNCOMPRESSED_BYTES,
        private readonly int $fetchMaxBytes = self::MAX_FETCH_BYTES,
    ) {}

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
                'path' => 'Invalid path',
            ]);
        }
        $home = $this->homeDirPath();
        if ($absolute && Str::startsWith('/'.$clean, $home)) {
            $clean = Str::after('/'.$clean, $home);
        }

        return $home.'/'.ltrim($clean, '/').($dir ? '/' : '');
    }

    /**
     * Written as the account, like every other operation here, so a symlink the account planted
     * (`~/x -> /etc/cron.d/x`, or into another account's home) cannot redirect a root write.
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
        $target = $targetDir.'/'.$file->getClientOriginalName();

        $this->writeAsUser($file->path(), $target);
    }

    /**
     * Copy an engine-side file to $target as the account, creating its parent directories. The source
     * is made readable for that: it is in the engine's own temp directory, which no account can reach.
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

    /** Every link followed, the last one too: test -e answers for the target. */
    public function exists(string $path): bool
    {
        $path = $this->resolvePath($path);
        $this->assertInsideHome([$path]);
        $process = $this->runOnCore(['test', '-e', $path]);

        return $process->getExitCode() === 0;
    }

    /**
     * A regular file inside the home, links followed, as the account sees it. Outside the home is
     * "no file", not a refusal: either answer about what is there would be an oracle.
     */
    public function isFile(string $path): bool
    {
        $path = $this->resolvePath($path);
        try {
            $this->assertInsideHome([$path]);
        } catch (\Exception) {
            return false;
        }

        return $this->runOnCore(['test', '-f', $path])->getExitCode() === 0;
    }

    /**
     * The entry at $path. Like exists(): a link out of the home is refused, not described.
     *
     * @return array<string, mixed>
     */
    public function stat(string $path): array
    {
        $path = $this->resolvePath($path);
        $this->assertInsideHome([$path]);
        if (in_array($this->entryType($path), ['missing', 'dangling'], true)) {
            throw new NotFoundException("No such file or directory: {$path}");
        }

        $directory = rtrim($path, '/');
        $process = $this->runOnCore(['find', $directory, '-maxdepth', '0', '-printf', self::ENTRY_FIELDS]);
        $this->assertSucceeded($process);
        $fields = $this->entryFields($process->getOutput());

        return $this->entryFromFields($directory, $fields[0]);
    }

    /**
     * The entries of one directory, read as the account. Directories first, then names in byte order.
     *
     * @return array{entries: list<array<string, mixed>>, path: string, truncated: bool, total: int}
     */
    public function list(string $path, bool $showHidden = true, int $limit = self::LIST_DEFAULT_LIMIT): array
    {
        $path = $this->resolvePath($path);
        $this->assertInsideHomeForList($path);
        $this->assertListable($path);

        $entries = $this->readEntries($path, $showHidden);
        $total = count($entries);
        $entries = array_slice($this->sortEntries($entries), 0, $limit);
        $this->flagSubdirectories($path, $entries);

        return [
            'entries' => array_values($entries),
            'path' => $this->homeRelativePath($path),
            'truncated' => $total > $limit,
            'total' => $total,
        ];
    }

    /** A listing is about a directory: a file is a refusal to act on, a missing path is not found. */
    private function assertListable(string $path): void
    {
        $type = $this->entryType($path);
        if (in_array($type, ['missing', 'dangling'], true)) {
            throw new NotFoundException("No such file or directory: {$path}");
        }
        if (! in_array($type, ['dir', 'dir-link'], true)) {
            throw ProblemException::one('path', 'not_a_directory', 'The path is not a directory.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readEntries(string $path, bool $showHidden): array
    {
        $directory = rtrim($path, '/');
        $process = $this->runOnCore([
            'find', $directory.'/', '-mindepth', '1', '-maxdepth', '1', '-printf', self::ENTRY_FIELDS,
        ]);
        $output = $process->getOutput();
        if ($process->getExitCode() !== 0 && $output === '') {
            throw new NotFoundException("No such file or directory: {$path}");
        }

        $entries = [];
        foreach ($this->entryFields($output) as $fields) {
            if ($showHidden || ! Str::startsWith($fields[0], '.')) {
                $entries[] = $this->entryFromFields($directory.'/'.$fields[0], $fields);
            }
        }

        return $entries;
    }

    /**
     * Directories first, then names in byte order; find returns the readdir order.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function sortEntries(array $entries): array
    {
        usort($entries, function (array $a, array $b): int {
            $byKind = ($a['type'] === 'dir' ? 0 : 1) <=> ($b['type'] === 'dir' ? 0 : 1);

            return $byKind !== 0 ? $byKind : strcmp($a['name'], $b['name']);
        });

        return $entries;
    }

    /**
     * @return list<list<string>>
     */
    private function entryFields(string $output): array
    {
        $fields = explode("\0", $output);
        array_pop($fields);

        return array_chunk($fields, substr_count(self::ENTRY_FIELDS, '\0'));
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function entryFromFields(string $absolutePath, array $fields): array
    {
        [$name, $type, $size, $mtime, $mode, $uid, $gid, $target, $targetType] = $fields;
        $isLink = $type === 'l';

        return [
            'name' => $name,
            'path' => $this->homeRelativePath($absolutePath),
            'type' => $this->entryTypeFromFind($type),
            'size' => (int) $size,
            'mtime' => (int) $mtime,
            'mode' => str_pad($mode, 4, '0', STR_PAD_LEFT),
            'uid' => (int) $uid,
            'gid' => (int) $gid,
            'target' => $isLink ? $target : null,
            'target_type' => $isLink ? $this->linkTargetType($targetType) : null,
            'has_subdirectories' => null,
        ];
    }

    private function entryTypeFromFind(string $type): string
    {
        return match ($type) {
            'd' => 'dir',
            'l' => 'link',
            default => 'file',
        };
    }

    private function linkTargetType(string $type): string
    {
        return match ($type) {
            'd' => 'dir',
            'f' => 'file',
            default => 'missing',
        };
    }

    /** A path as every other file route accepts it: relative to the home, with a leading slash. */
    private function homeRelativePath(string $path): string
    {
        $home = rtrim($this->homeDirPath(), '/');
        $relative = rtrim(Str::after(rtrim($path, '/'), $home), '/');

        return $relative === '' ? '/' : $relative;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function flagSubdirectories(string $path, array &$entries): void
    {
        $parents = null;
        foreach ($entries as &$entry) {
            if ($entry['type'] !== 'dir') {
                continue;
            }
            $parents ??= $this->subdirectoryParents($path);
            $entry['has_subdirectories'] = isset($parents[$entry['name']]);
        }
    }

    /**
     * Names of the immediate subdirectories that themselves hold a directory, in one find.
     *
     * @return array<string, true>
     */
    private function subdirectoryParents(string $path): array
    {
        $process = $this->runOnCore([
            'find', rtrim($path, '/').'/', '-mindepth', '2', '-maxdepth', '2', '-type', 'd', '-printf', '%h\0',
        ]);
        $parents = [];
        foreach (explode("\0", $process->getOutput()) as $parent) {
            if ($parent !== '') {
                $parents[basename($parent)] = true;
            }
        }

        return $parents;
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
            if (! $this->isDirectory($workdir)) {
                throw new \Exception('Invalid path');
            }
        }

        $allowed = $ignoreEmpty ? [0, ...self::ZIP_EMPTY_EXIT_CODES] : [0];
        $this->assertSucceeded(
            $this->runOnCore($this->zipCommand($zipPath, $path, $compressionLevel, $fromDate), $workdir),
            $allowed,
        );
    }

    /**
     * @return list<string>
     */
    private function zipCommand(string $zipPath, string $path, ?int $compressionLevel, ?string $fromDate): array
    {
        $recurse = $compressionLevel === null ? '-r' : '-r'.$compressionLevel;
        $command = ['zip', $recurse];
        if (is_string($fromDate) && $fromDate !== '') {
            $command[] = '--from-date';
            $command[] = $fromDate;
        }
        $command[] = $zipPath;
        $command[] = $path;

        return $command;
    }

    /**
     * Extract a .zip, .tar.gz or .gz into $path. The archive is checked on a root-owned read-only copy and
     * unpacked into a fresh directory no account can reach, so no link the account planted is written through;
     * each unpacked top-level entry is then renamed into $path, which must not hold it yet.
     */
    public function unzip(string $zipPath, string $path): void
    {
        $zipPath = $this->resolvePath($zipPath);
        $path = $this->resolvePath($path);
        $filename = basename($zipPath);
        $isZip = Str::endsWith($filename, '.zip');
        $isTar = ! $isZip && Str::endsWith($filename, '.tar.gz');
        $this->assertInsideHome([$zipPath, $path]);

        $this->sweepStaleUnzipStages();
        $stageDir = $this->unzipStageRoot().'/'.bin2hex(random_bytes(8));
        $staged = $stageDir.'/'.$filename;
        $unpacked = $stageDir.'/unpacked';
        try {
            $this->stageReadOnlyCopy($zipPath, $stageDir, $staged);
            $this->assertArchiveIsSafe($zipPath, $staged, $isZip, $isTar);
            $this->makeUnpackDirectory($unpacked);
            $this->assertSucceeded($this->unpack($staged, $unpacked, $isZip, $isTar));
            $this->moveUnpackedInto($unpacked, $path);
            $this->removeUnpackedGzip($zipPath, $path, $isZip || $isTar);
        } finally {
            $this->project->system()->runProcess(['sudo', 'rm', '-rf', $stageDir]);
        }
    }

    private function unzipStageRoot(): string
    {
        return $this->unzipStageRoot ?? $this->project->system()->homesDirPath().'/'.self::UNZIP_STAGE_NAME;
    }

    /** Best effort: a killed request skips its `finally`, and nothing else removes what it unpacked. */
    private function sweepStaleUnzipStages(): void
    {
        $this->project->system()->runProcess([
            'sudo', 'find', $this->unzipStageRoot(), '-mindepth', '1', '-maxdepth', '1',
            '-mmin', '+'.self::UNZIP_STALE_MINUTES, '-exec', 'rm', '-rf', '--', '{}', '+',
        ]);
    }

    /**
     * Copy the archive into $stageDir and make it root-owned and read-only. Read as the account:
     * root's copy read what the home check, run as the account, could not see, such as an archive in ~/docker.
     */
    private function stageReadOnlyCopy(string $zipPath, string $stageDir, string $staged): void
    {
        $system = $this->project->system();
        $system->exec(['sudo', 'mkdir', '-p', $stageDir]);
        $system->exec(['sudo', 'chown', implode(':', $this->accountIds()), $stageDir]);
        $this->assertSucceeded($this->runOnCore([
            'sh', '-c', '[ -f "$1" ] || { echo "Not a regular file: $1" >&2; exit 1; }; cat -- "$1" > "$2"',
            'sh', $zipPath, $staged,
        ]));
        $system->exec(['sudo', 'chown', 'root:root', $stageDir, $staged]);
        $system->exec(['sudo', 'chmod', '0755', $stageDir]);
        $system->exec(['sudo', 'chmod', '0444', $staged]);
    }

    /**
     * The size limit and what a deploy refuses in an archive, refused here too.
     *
     * @throws ValidationException
     */
    private function assertArchiveIsSafe(string $zipPath, string $staged, bool $isZip, bool $isTar): void
    {
        $system = $this->project->system();
        try {
            ArchiveUnpackedSize::assertWithin($staged, $isZip, $this->unzipMaxBytes);
            if ($isZip || $isTar) {
                UploadedArchive::confined($zipPath, $isZip)
                    ->assertSafeContents($staged, fn (array $argv): string => $system->exec($argv, [], 120));
            }
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            throw ValidationException::withMessages(['zip_path' => $e->getMessage()]);
        }
    }

    /** The account's own directory inside the root-owned stage: the archive is unpacked as the account. */
    private function makeUnpackDirectory(string $unpacked): void
    {
        $system = $this->project->system();
        $system->exec(['sudo', 'mkdir', $unpacked]);
        $system->exec(['sudo', 'chown', implode(':', $this->accountIds()), $unpacked]);
    }

    /** A plain .gz unpacks to its own name without the `.gz`. */
    private function unpack(string $staged, string $unpacked, bool $isZip, bool $isTar): Process
    {
        if ($isZip) {
            return $this->runOnCore(['unzip', '-o', $staged, '-d', $unpacked]);
        }
        if ($isTar) {
            return $this->runOnCore(['tar', '-zxvf', $staged, '-C', $unpacked]);
        }

        return $this->runOnCore([
            'sh', '-c', 'gzip -dc -- "$1" > "$2"', 'sh', $staged, $unpacked.'/'.Str::beforeLast(basename($staged), '.gz'),
        ]);
    }

    /**
     * Never over something already there: a rename would replace it, and a merge would follow
     * any link the account planted in the destination.
     */
    private function moveUnpackedInto(string $unpacked, string $path): void
    {
        $process = $this->runOnCore(['sh', '-c', self::MOVE_UNPACKED_SCRIPT, 'sh', $unpacked, $this->homeDirPath(), $path]);
        if ($process->getExitCode() === self::MOVE_TAKEN_EXIT_CODE) {
            throw $this->destinationTaken($path, explode("\0", rtrim($process->getOutput(), "\0")));
        }
        $this->assertSucceeded($process);
    }

    /** @param list<string> $names */
    private function destinationTaken(string $path, array $names): ProblemException
    {
        $shown = implode(', ', array_slice($names, 0, self::TAKEN_NAMES_SHOWN));
        $more = count($names) > self::TAKEN_NAMES_SHOWN ? ', ...' : '';

        return ProblemException::conflict(
            'path',
            'destination_exists',
            $this->homeRelativePath($path).' already holds '.count($names).' of the entries the archive unpacks to '
                ."({$shown}{$more}). Nothing was extracted: extract into a folder that does not hold them.",
        );
    }

    /** gunzip's own result: the `.gz` is removed when it was already in the target directory. */
    private function removeUnpackedGzip(string $zipPath, string $path, bool $isArchive): void
    {
        if (! $isArchive && $zipPath === rtrim($path, '/').'/'.basename($zipPath)) {
            $this->runOnCore(['rm', '-f', $zipPath]);
        }
    }

    /**
     * The entry itself may be a link pointing anywhere: rm removes the link, and a link
     * whose target is gone is still there to remove.
     */
    public function remove(string $path, bool $recursive = false): void
    {
        $path = $this->resolvePath($path);
        $this->assertInsideHome([], [$path]);
        $this->assertRemovable($path, $recursive);
        $command = ['rm', '-f'];
        if ($recursive) {
            $command[] = '-r';
        }
        $command[] = $path;

        $this->assertSucceeded($this->runOnCore($command));
    }

    /** rm -f is silent about a missing path, and answers a bare "Is a directory" without -r. */
    private function assertRemovable(string $path, bool $recursive): void
    {
        $type = $this->entryType($path);
        if ($type === 'missing') {
            throw new NotFoundException("No such file or directory: {$path}");
        }
        if ($type === 'dir' && ! $recursive) {
            throw ValidationException::withMessages([
                'recursive' => 'The path is a directory. Set recursive to true to delete it and everything in it.',
            ]);
        }
    }

    /** Megabytes under $directory, without the DinD data-root. */
    public function diskUsage(string $directory = '/'): int
    {
        $path = $this->resolvePath($directory);
        $process = $this->runOnCore(['du', '-shm', '--exclude='.$this->dockerDataRoot(), $path]);
        $this->assertSucceeded($process);
        $mb = Str::before($process->getOutput(), "\t");

        return (int) $mb;
    }

    /**
     * Bytes the inner Docker's json-file container logs take, rotated files included; 0 before
     * the containers directory exists. They sit in ~/docker, which diskUsage() leaves out but
     * the quota does not; root-owned, so read as root, and find follows no link.
     */
    public function containerLogBytes(): int
    {
        $dir = $this->dockerDataRoot().'/containers';
        $process = $this->project->system()->runProcess([
            'sudo', 'find', $dir, '-mindepth', '2', '-maxdepth', '2', '-type', 'f', '-name', '*-json.log*', '-printf', '%s\n',
        ]);
        $bytes = 0;
        foreach (explode("\n", $process->getOutput()) as $line) {
            if (ctype_digit($line)) {
                $bytes += (int) $line;
            }
        }

        return $bytes;
    }

    /**
     * ~/docker, the DinD data-root: root-owned 0700, unreadable as the account, and
     * engine images and build cache rather than the project's own files.
     */
    private function dockerDataRoot(): string
    {
        return rtrim($this->homeDirPath(), '/').'/docker';
    }

    public function moveDirectoryContents(string $source, string $dest, bool $override = true): void
    {
        $sourceDir = rtrim($this->resolvePath($source), '/');
        $destDir = rtrim($this->resolvePath($dest), '/');
        $this->assertInsideHome([$sourceDir, $destDir]);
        if (! $this->isDirectory($destDir)) {
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

    /**
     * Download $url into $destinationDir as the account. The body lands in a partial file beside the
     * destination and is moved there once complete, so a transfer cut off at the size limit leaves nothing behind.
     */
    public function fetch(string $url, string $destinationDir, ?string $filename = null): void
    {
        $this->assertFetchableScheme($url);

        $filename ??= $this->filenameFromUrl($url);
        if ($this->filenameIsUnsafe($filename)) {
            throw new \Exception('Invalid filename');
        }

        $dir = rtrim($this->resolvePath($destinationDir), '/');
        $this->assertInsideHome([$dir.'/'.$filename]);
        if (! $this->isDirectory($dir)) {
            throw new \Exception('Destination directory does not exist');
        }

        $partial = $dir.'/.pa-fetch-'.bin2hex(random_bytes(6)).'.part';
        try {
            $this->download($url, $partial);
            $this->assertSucceeded($this->runOnCore(['mv', '-fT', '--', $partial, $dir.'/'.$filename]));
        } finally {
            $this->runOnCore(['rm', '-f', '--', $partial]);
        }
    }

    /** Checked before the destination is touched: `file://` must not create a file. */
    private function assertFetchableScheme(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \Exception('Only http and https URLs can be fetched');
        }
    }

    /**
     * $url into $destination, as the account. Redirects are followed here, not by curl -L, so every
     * hop is checked against OutboundUrl: a public URL may redirect to the metadata service.
     */
    private function download(string $url, string $destination): void
    {
        $current = $url;

        for ($hop = 0; $hop <= OutboundUrl::MAX_REDIRECTS; $hop++) {
            $this->assertOutboundAllowed($current);

            $process = $this->runOnCore($this->downloadCommand($current, $destination));
            $status = (int) trim($process->getOutput());

            if ($this->isRedirect($status)) {
                $current = $this->redirectTarget($current, $status);

                continue;
            }

            $this->assertDownloaded($process, $current);

            return;
        }

        throw new \Exception("Fetching {$url} followed more than ".OutboundUrl::MAX_REDIRECTS.' redirects');
    }

    /**
     * Since curl 8.4 --max-filesize also stops a transfer with no Content-Length once it grows past the limit.
     *
     * @return list<string>
     */
    private function downloadCommand(string $url, string $destination): array
    {
        return $this->pinnedCurl([
            'curl',
            '-fsS',
            '--max-filesize', (string) $this->fetchMaxBytes,
            '-o', $destination,
            '-w', '%{http_code}',
        ], $url);
    }

    /** A 3xx is not an error to curl -f. */
    private function isRedirect(int $status): bool
    {
        return $status >= 300 && $status < 400;
    }

    private function redirectTarget(string $url, int $status): string
    {
        $location = $this->redirectLocation($url);
        if ($location === null) {
            throw new \Exception("Fetching {$url} answered HTTP {$status} with no Location");
        }

        return OutboundUrl::absoluteUrl($url, $location);
    }

    private function assertDownloaded(Process $process, string $url): void
    {
        if ($process->getExitCode() === self::CURL_FILE_TOO_LARGE) {
            throw new \Exception('The file at '.$url.' is larger than the '.intdiv($this->fetchMaxBytes, 1048576).' MB limit');
        }
        $this->assertSucceeded($process);
    }

    /** A seam for tests that fetch from a local fixture server. */
    protected function assertOutboundAllowed(string $url): void
    {
        OutboundUrl::assertAllowed($url);
    }

    /** @see assertOutboundAllowed() */
    protected function outboundPin(string $url): ?string
    {
        return OutboundUrl::pin($url);
    }

    /**
     * $curl for $url, connected to the address that was checked so a second
     * lookup cannot point it elsewhere.
     *
     * @param  list<string>  $curl
     * @return list<string>
     */
    private function pinnedCurl(array $curl, string $url): array
    {
        $pin = $this->outboundPin($url);
        if ($pin !== null) {
            array_push($curl, '--resolve', $pin);
        }
        $curl[] = $url;

        return $curl;
    }

    /** Ask curl for the Location of a redirect without writing a body. */
    private function redirectLocation(string $url): ?string
    {
        $location = trim($this->runOnCore($this->pinnedCurl([
            'curl',
            '-sS',
            '-o', '/dev/null',
            '-w', '%{redirect_url}',
        ], $url))->getOutput());

        return $location === '' ? null : $location;
    }

    public function chmod(string $path, string $mode): void
    {
        if (! preg_match('/\A[0-7]{3,4}\z/', $mode)) {
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
     * @param  list<string>  $command
     */
    private function runOnCore(array $command, ?string $workdir = null): Process
    {
        if ($workdir !== null) {
            $command = ['env', '--chdir='.$workdir, ...$command];
        }

        return $this->project->system()->runProcess($this->asAccount($command));
    }

    /**
     * $command run as the account, by uid and gid.
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    public function asAccount(array $command): array
    {
        [$uid, $gid] = $this->accountIds();

        return [
            'sudo',
            'setpriv',
            '--reuid',
            $uid,
            '--regid',
            $gid,
            '--clear-groups',
            ...$command,
        ];
    }

    /** @return array{0: string, 1: string} the uid and gid file commands run as */
    private function accountIds(): array
    {
        $model = $this->project->model();

        return array_map('strval', $model->linuxIds());
    }

    /**
     * Refuse a path that leaves the home once symlinks are followed. The
     * commands run as the account, but in the engine's container: a link the
     * account planted (`~/x -> /tmp/x`) would take a write anywhere that uid can
     * write there. Checked as the account, so the check sees what it sees.
     *
     * @param  list<string>  $paths  every symlink followed, the last one too
     * @param  list<string>  $entries  the last component is the entry itself
     *                                 (what rm removes, what mv and cp take)
     */
    private function assertInsideHome(array $paths, array $entries = []): void
    {
        $this->assertSucceeded($this->confine($paths, $entries));
    }

    /** A listing through a link out of the home is a refusal the caller can act on, not a bad request. */
    private function assertInsideHomeForList(string $path): void
    {
        $process = $this->confine([$path]);
        if ($process->getExitCode() === self::CONFINE_OUTSIDE_EXIT_CODE) {
            throw ProblemException::one('path', 'outside_home', trim($process->getErrorOutput()));
        }
        $this->assertSucceeded($process);
    }

    /**
     * @param  list<string>  $paths  every symlink followed, the last one too
     * @param  list<string>  $entries  the last component is the entry itself
     */
    private function confine(array $paths, array $entries = []): Process
    {
        $args = [
            ...array_map(fn (string $p): string => 'F:'.$p, $paths),
            ...array_map(fn (string $p): string => 'E:'.$p, $entries),
        ];

        return $this->runOnCore([
            'sh', '-c', self::CONFINE_SCRIPT, 'sh', $this->homeDirPath(), ...$args,
        ]);
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
     * @param  list<int>  $allowedExitCodes
     */
    private function assertSucceeded(Process $process, array $allowedExitCodes = [0]): void
    {
        $code = $process->getExitCode();
        if (in_array($code, $allowedExitCodes, true)) {
            return;
        }

        $message = ($process->getErrorOutput() ?: $process->getOutput())." (exit code {$code})";
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
