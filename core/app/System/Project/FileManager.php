<?php

namespace App\System\Project;

use App\Exceptions\NotFoundException;
use App\Exceptions\ProblemException;
use App\Lib\Deploy\Source\ArchiveSafety;
use App\Lib\Deploy\Source\ArchiveUnpackedSize;
use App\Lib\Deploy\Source\UploadedArchive;
use App\Lib\Http\OutboundUrl;
use App\System\Privileged;
use App\System\Privileged\AccountFileManager;
use App\System\Privileged\Exchange;
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

    /** The confinement and move scripts' exit code for a path that resolves outside the home. */
    private const CONFINE_OUTSIDE_EXIT_CODE = 3;

    /** The write and mkdir scripts' exit code for a folder the account cannot write to. */
    private const WRITE_UNWRITABLE_EXIT_CODE = 6;

    /** The move script's exit code for entries the destination already holds. */
    private const MOVE_TAKEN_EXIT_CODE = 4;

    /**
     * The move script's exit code for a rename that failed after the destination was checked and found writable:
     * an entry appeared there in between, and may already be gone again, so it is not looked at a second time.
     */
    private const MOVE_RACED_EXIT_CODE = 5;

    /** The move script's exit code for a destination, or the folder it would be made in, the account cannot write to; it prints that folder. */
    private const MOVE_READ_ONLY_EXIT_CODE = 6;

    /** What unzip unpacks, by the archive's name, case aside: `.tar.gz` ahead of `.gz`, which it ends in too. */
    private const UNZIP_FORMATS = ['.zip' => 'zip', '.tar.gz' => 'tar', '.tgz' => 'tar', '.gz' => 'gz'];

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
        if (self::isAtOrBelow($path, '/var/www')) {
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
        if ($absolute && self::isAtOrBelow('/'.$clean, $home)) {
            $clean = Str::after('/'.$clean, $home);
        }

        return $home.'/'.ltrim($clean, '/').($dir ? '/' : '');
    }

    /** By whole segments: `/home/alice2` is not below `/home/alice`. */
    private static function isAtOrBelow(string $path, string $dir): bool
    {
        return $path === $dir || Str::startsWith($path, rtrim($dir, '/').'/');
    }

    /**
     * Written as the account, like every other operation here, so a symlink the account planted
     * (`~/x -> /etc/cron.d/x`, or into another account's home) cannot redirect a root write.
     */
    public function putContents(string $path, string $contents): void
    {
        $path = $this->resolvePath($path);
        $staged = Exchange::file('pa-put-');
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
        $process = $this->runAsAccount($this->commands()->makeFolder($path, $parents));
        if ($process->getExitCode() === self::WRITE_UNWRITABLE_EXIT_CODE) {
            throw $this->unwritableFolder($process->getOutput());
        }
        $this->assertSucceeded($process);
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
     * is made readable for that: it is in the exchange directory, which no account can list.
     */
    private function writeAsUser(string $source, string $target): void
    {
        if (! Exchange::holds($source)) {
            $this->writeStagedAsUser($source, $target);

            return;
        }
        $this->assertInsideHome([$target]);
        @chmod($source, 0644);
        $process = $this->runAsAccount($this->commands()->writeCopy($source, $target));
        if ($process->getExitCode() === self::WRITE_UNWRITABLE_EXIT_CODE) {
            throw $this->unwritableFolder(dirname($target));
        }
        $this->assertSucceeded($process);
    }

    /** Only the exchange directory is the root broker's too. */
    private function writeStagedAsUser(string $source, string $target): void
    {
        $staged = Exchange::file('pa-put-');
        try {
            if (! @copy($source, $staged)) {
                throw new \RuntimeException('Cannot stage the file contents');
            }
            $this->writeAsUser($staged, $target);
        } finally {
            @unlink($staged);
        }
    }

    private function unwritableFolder(string $dir): ProblemException
    {
        return ProblemException::one(
            'path',
            'not_writable',
            $this->homeRelativePath($dir)." is not a folder the project's account can write to: choose a folder inside the project.",
        );
    }

    /** Every link followed, the last one too: test -e answers for the target. */
    public function exists(string $path): bool
    {
        $path = $this->resolvePath($path);
        $this->assertInsideHome([$path]);
        $process = $this->runAsAccount($this->commands()->exists($path));

        return $process->getExitCode() === 0;
    }

    /**
     * A regular file inside the home, links followed, as the account sees it. Outside the home is
     * "no file", not a refusal: either answer about what is there would be an oracle.
     */
    public function isFile(string $path): bool
    {
        try {
            return $this->isInsideHomeFile($path);
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * {@see isFile()}, refusing a path outside the home instead of answering "no file": a caller about to read
     * the file says why it cannot rather than claiming there is none.
     */
    public function isInsideHomeFile(string $path): bool
    {
        $path = $this->resolvePath($path);
        try {
            $this->assertInsideHome([$path]);
        } catch (ProblemException $e) {
            throw $e;
        } catch (\Exception) {
            return false;
        }

        return $this->runAsAccount($this->commands()->isFile($path))->getExitCode() === 0;
    }

    /**
     * Where the regular file at the absolute $path lands, every link followed, as the account sees
     * it; null when there is none. Not confined: the caller judges where it lands, as UploadedArchive does.
     */
    public function resolvedFile(string $path): ?string
    {
        $process = $this->runAsAccount($this->commands()->resolvedFile($path));
        $target = rtrim($process->getOutput(), "\n");

        return $process->getExitCode() === 0 && $target !== '' ? $target : null;
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
        $process = $this->runAsAccount($this->commands()->entryFields($directory));
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
        $this->assertFieldInsideHome('path', $path);
        $this->assertListable($path);

        [$total, $entries] = $this->readPage($path, $showHidden, $limit);
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
     * The first $limit entries, directories first and then names in byte order, and how many there are.
     * A directory may hold millions, so the account sorts them and PHP never holds more than the page.
     *
     * @return array{0: int, 1: list<array<string, mixed>>}
     */
    private function readPage(string $path, bool $showHidden, int $limit): array
    {
        $directory = rtrim($path, '/');
        $process = $this->runAsAccount($this->commands()->listPage($directory.'/', $limit, $showHidden));
        $output = $process->getOutput();
        if ($process->getExitCode() !== 0 && $output === '') {
            throw new NotFoundException("No such file or directory: {$path}");
        }
        if ($output === '') {
            return [0, []];
        }

        [$total, $rows] = explode("\0", $output, 2);
        $entries = array_map(
            fn (array $fields): array => $this->entryFromFields($directory.'/'.$fields[0], $fields),
            $this->entryFields($rows),
        );

        return [(int) $total, $entries];
    }

    /**
     * @return list<list<string>>
     */
    private function entryFields(string $output): array
    {
        $fields = explode("\0", $output);
        array_pop($fields);

        return array_chunk($fields, substr_count(AccountFileManager::ENTRY_FIELDS, '\0'));
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
        $process = $this->runAsAccount($this->commands()->subdirectoryParents(rtrim($path, '/').'/'));
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
        $this->assertSucceeded($this->runAsAccount($this->commands()->moveEntry($sourcePath, $destPath)));
    }

    public function cp(string $sourcePath, string $destPath): void
    {
        $sourcePath = $this->resolvePath($sourcePath);
        $destPath = $this->resolvePath($destPath);
        $this->assertInsideHome([$destPath], [$sourcePath]);
        $this->assertSucceeded($this->runAsAccount($this->commands()->copyEntry($sourcePath, $destPath)));
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

        $command = $this->commands()->zip($zipPath, $path, $compressionLevel, $fromDate);
        if ($skipParents) {
            $workdir = dirname($path);
            if (! $this->isDirectory($workdir)) {
                throw new \Exception('Invalid path');
            }
            $command = $this->commands()->zipFrom($workdir, $zipPath, basename($path), $compressionLevel, $fromDate);
        }

        $allowed = $ignoreEmpty ? [0, ...self::ZIP_EMPTY_EXIT_CODES] : [0];
        $this->assertSucceeded($this->runAsAccount($command), $allowed);
    }

    /**
     * Extract a .zip, .tar.gz or .tgz, or .gz into $path: checked on a root-owned read-only copy and unpacked where no account
     * reaches, so no planted link is written through, then each top-level entry is renamed into $path, which must not hold it yet.
     *
     * @return list<string> what unzip warned about and extracted past, such as backslashes it read as folder separators
     */
    public function unzip(string $zipPath, string $path): array
    {
        $zipPath = $this->resolvePath($zipPath);
        $path = $this->resolvePath($path);
        $filename = basename($zipPath);
        $this->assertFieldInsideHome('zip_path', $zipPath);
        $this->assertFieldInsideHome('path', $path);
        $this->assertArchiveExists($zipPath);
        $format = self::unzipFormat($filename);
        $isZip = $format === 'zip';
        $isTar = $format === 'tar';

        $this->sweepStaleUnzipStages();
        $stageDir = $this->unzipStageRoot().'/'.bin2hex(random_bytes(8));
        $staged = $stageDir.'/'.$filename;
        $unpacked = $stageDir.'/unpacked';
        try {
            $this->stageReadOnlyCopy($zipPath, $stageDir, $staged);
            $this->assertArchiveIsSafe($zipPath, $staged, $isZip, $isTar);
            $this->makeUnpackDirectory($unpacked);
            $warnings = $this->unpack($zipPath, $staged, $unpacked, $isZip, $isTar);
            $this->moveUnpackedInto($unpacked, $path);
            $this->removeUnpackedGzip($zipPath, $path, $isZip || $isTar);

            return $warnings;
        } finally {
            $this->project->system()->runProcess(Privileged::files()->removeTree($stageDir));
        }
    }

    /**
     * The format the archive's name says; any other name is refused, as the archive deploy refuses one, rather than handed to gzip.
     *
     * @return 'zip'|'tar'|'gz'
     *
     * @throws ValidationException
     */
    private static function unzipFormat(string $filename): string
    {
        $name = strtolower($filename);
        foreach (self::UNZIP_FORMATS as $suffix => $format) {
            if (str_ends_with($name, $suffix)) {
                return $format;
            }
        }

        throw ValidationException::withMessages(['zip_path' => 'Unsupported archive format, expected .zip, .tar.gz, .tgz or .gz']);
    }

    /** A regular file, links followed, as the account that stages it sees it. */
    private function assertArchiveExists(string $zipPath): void
    {
        if ($this->resolvedFile($zipPath) === null) {
            throw ProblemException::one('zip_path', 'not_found', 'Archive not found: '.$this->homeRelativePath($zipPath));
        }
    }

    private function unzipStageRoot(): string
    {
        return $this->unzipStageRoot ?? $this->project->system()->homesDirPath().'/'.self::UNZIP_STAGE_NAME;
    }

    /** Best effort: a killed request skips its `finally`, and nothing else removes what it unpacked. */
    private function sweepStaleUnzipStages(): void
    {
        $this->project->system()->runProcess(Privileged::files()->removeEntriesOlderThan($this->unzipStageRoot(), self::UNZIP_STALE_MINUTES));
    }

    /**
     * Copy the archive into $stageDir and make it root-owned and read-only. Read as the account:
     * root's copy read what the home check, run as the account, could not see, such as an archive in ~/docker.
     */
    private function stageReadOnlyCopy(string $zipPath, string $stageDir, string $staged): void
    {
        $system = $this->project->system();
        $system->exec(Privileged::files()->makeDirectories($stageDir));
        $system->exec(Privileged::files()->changeOwner(implode(':', $this->accountIds()), $stageDir));
        $this->assertSucceeded($this->runAsAccount($this->commands()->copyArchiveToStage($zipPath, $staged)));
        $system->exec(Privileged::files()->changeOwner('root:root', $stageDir, $staged));
        $system->exec(Privileged::files()->changeMode('0755', $stageDir));
        $system->exec(Privileged::files()->changeMode('0444', $staged));
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
            if ($isZip || $isTar) {
                UploadedArchive::confined($zipPath, $isZip)
                    ->assertSafeContents($staged, fn (array $argv): string => $system->exec($argv, [], 120));
            }
            self::assertUnpackedSizeWithin($zipPath, $staged, $isZip, $this->unzipMaxBytes);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            throw ValidationException::withMessages(['zip_path' => $e->getMessage()]);
        }
    }

    /** After the listing, as an archive deploy checks it, so an archive unzip cannot read is named by the listing's own error. */
    private static function assertUnpackedSizeWithin(string $zipPath, string $staged, bool $isZip, int $maxBytes): void
    {
        try {
            ArchiveUnpackedSize::assertWithin($staged, $isZip, $maxBytes);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Could not read '.basename($zipPath).': '.$e->getMessage(), 0, $e);
        }
    }

    /** The account's own directory inside the root-owned stage: the archive is unpacked as the account. */
    private function makeUnpackDirectory(string $unpacked): void
    {
        $system = $this->project->system();
        $system->exec(Privileged::files()->makeDirectory($unpacked));
        $system->exec(Privileged::files()->changeOwner(implode(':', $this->accountIds()), $unpacked));
    }

    /**
     * unzip's warning exit is an extraction it finished, which the broker answers as 0; any other exit fails.
     *
     * @return list<string> unzip's warnings, naming the archive by its own name rather than its staged copy
     */
    private function unpack(string $zipPath, string $staged, string $unpacked, bool $isZip, bool $isTar): array
    {
        $archive = UploadedArchive::confined($zipPath, $isZip);
        $extraction = $this->extract($staged, $unpacked, $isZip, $isTar);
        $this->assertUnpacked($archive, $extraction, $staged);

        return $archive->extractionWarnings($extraction, $staged);
    }

    /**
     * An archive its extractor could not unpack is refused like one the checks refuse, by its own name rather than its staged copy.
     *
     * @throws ValidationException
     */
    private function assertUnpacked(UploadedArchive $archive, Process $extraction, string $staged): void
    {
        try {
            $archive->assertExtracted($extraction, $staged);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['zip_path' => $e->getMessage()]);
        }
    }

    /** A plain .gz unpacks to its own name without the `.gz`. */
    private function extract(string $staged, string $unpacked, bool $isZip, bool $isTar): Process
    {
        if ($isZip) {
            return $this->runAsAccount($this->commands()->unzipInto($staged, $unpacked));
        }
        if ($isTar) {
            return $this->runAsAccount($this->commands()->untarInto($staged, $unpacked));
        }

        return $this->runAsAccount($this->commands()->gunzipTo($staged, $unpacked.'/'.substr(basename($staged), 0, -strlen('.gz'))));
    }

    /**
     * Never over something already there: a rename would replace it, and a merge would follow
     * any link the account planted in the destination.
     */
    private function moveUnpackedInto(string $unpacked, string $path): void
    {
        $process = $this->runAsAccount($this->commands()->moveUnpackedInto($unpacked, $this->homeDirPath(), $path));
        $refusal = $this->moveRefusal($process, $path);
        if ($refusal !== null) {
            throw $refusal;
        }
        $this->assertSucceeded($process);
    }

    private function moveRefusal(Process $move, string $path): ?ProblemException
    {
        return match ($move->getExitCode()) {
            self::MOVE_TAKEN_EXIT_CODE => $this->destinationTaken($path, explode("\0", rtrim($move->getOutput(), "\0"))),
            self::MOVE_RACED_EXIT_CODE => $this->destinationTakenDuringMove($path, rtrim($move->getOutput(), "\0")),
            self::MOVE_READ_ONLY_EXIT_CODE => $this->unwritableFolder($move->getOutput()),
            self::CONFINE_OUTSIDE_EXIT_CODE => $this->outsideHome('path', $move),
            default => null,
        };
    }

    private function destinationTakenDuringMove(string $path, string $name): ProblemException
    {
        return ProblemException::conflict(
            'path',
            'destination_exists',
            "{$name} could not be moved into ".$this->homeRelativePath($path).': an entry of that name appeared there while the archive '
                .'was being extracted. Entries extracted before it stay in place: extract into a folder that does not hold them.',
        );
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
            $this->runAsAccount($this->commands()->removeEntry($zipPath, false));
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

        $this->assertSucceeded($this->runAsAccount($this->commands()->removeEntry($path, $recursive)));
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
        $process = $this->runAsAccount($this->commands()->diskUsageExcluding($path, $this->dockerDataRoot()));
        $this->assertSucceeded($process);
        $mb = Str::before($process->getOutput(), "\t");

        return (int) $mb;
    }

    /**
     * Bytes the inner Docker's json-file container logs take, rotated ones included; 0 before the containers directory
     * exists. Their ~/docker counts for the quota but not diskUsage(); root-owned, so read as root, following no link.
     */
    public function containerLogBytes(): int
    {
        $dir = $this->dockerDataRoot().'/containers';
        $process = $this->project->system()->runProcess(Privileged::files()->containerLogSizes($dir));
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

        $this->assertSucceeded($this->runAsAccount($this->commands()->moveEntriesInto($sourceDir, $destDir, $override)));
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
            $this->assertSucceeded($this->runAsAccount($this->commands()->renameOver($partial, $dir.'/'.$filename)));
        } finally {
            $this->runAsAccount($this->commands()->removeFile($partial));
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

            $process = $this->runAsAccount($this->commands()->download($current, $destination, $this->fetchMaxBytes, $this->outboundPin($current)));
            $status = (int) trim($process->getOutput());

            if ($this->isRedirect($status)) {
                $current = $this->redirectTarget($current, $status);

                continue;
            }

            $this->assertDownloaded($process, $current, $status);

            return;
        }

        throw new \Exception("Fetching {$url} followed more than ".OutboundUrl::MAX_REDIRECTS.' redirects');
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

    private function assertDownloaded(Process $process, string $url, int $status): void
    {
        $code = (int) $process->getExitCode();
        if ($code === self::CURL_FILE_TOO_LARGE) {
            throw new \Exception('The file at '.$url.' is larger than the '.intdiv($this->fetchMaxBytes, 1048576).' MB limit');
        }
        if ($code !== 0) {
            throw DownloadFailure::problem($code, $url, $status);
        }
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
     * Asks curl for a redirect's Location without a body, pinned to the address that was checked.
     */
    private function redirectLocation(string $url): ?string
    {
        $location = trim($this->runAsAccount($this->commands()->redirectLocation($url, $this->outboundPin($url)))->getOutput());

        return $location === '' ? null : $location;
    }

    public function chmod(string $path, string $mode): void
    {
        if (! preg_match('/\A[0-7]{3,4}\z/', $mode)) {
            throw new \Exception('Invalid mode');
        }

        $path = $this->resolvePath($path);
        $this->assertInsideHome([$path]);
        $this->assertSucceeded($this->runAsAccount($this->commands()->setMode($path, $mode)));
    }

    /**
     * As the project user by uid and gid, since its passwd entry is not in the core container.
     *
     * @param  list<string>  $argv
     */
    private function runAsAccount(array $argv): Process
    {
        return $this->project->system()->runProcess($argv);
    }

    private function commands(): AccountFileManager
    {
        return Privileged::accountFileManager(...$this->accountIds());
    }

    /** @return array{0: int, 1: int} the uid and gid file commands run as */
    public function accountIds(): array
    {
        return $this->project->model()->linuxIds();
    }

    /**
     * Refuse a path that leaves the home once links are followed: commands run as the account but in the engine's
     * container, where a planted link would take a write anywhere the uid can write; checked as the account, as it sees.
     *
     * @param  list<string>  $paths  every symlink followed, the last one too
     * @param  list<string>  $entries  the last component is the entry itself
     *                                 (what rm removes, what mv and cp take)
     */
    private function assertInsideHome(array $paths, array $entries = [], string $field = 'path'): void
    {
        $process = $this->confine($paths, $entries);
        if ($process->getExitCode() === self::CONFINE_OUTSIDE_EXIT_CODE) {
            throw $this->outsideHome($field, $process);
        }
        $this->assertSucceeded($process);
    }

    /** A path through a link out of the home is a refusal on the field that named it, not a bad request. */
    private function assertFieldInsideHome(string $field, string $path): void
    {
        $this->assertInsideHome([$path], [], $field);
    }

    /** What the script that refused the path said of it. */
    private function outsideHome(string $field, Process $refusal): ProblemException
    {
        return ProblemException::one($field, 'outside_home', trim($refusal->getErrorOutput()));
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

        return $this->runAsAccount($this->commands()->confine($this->homeDirPath(), $args));
    }

    /**
     * What is at $path, asked as the account like the command it guards: the
     * engine's PHP is another user and cannot enter the account's private directories.
     *
     * @return 'missing'|'dangling'|'dir'|'dir-link'|'file'
     */
    private function entryType(string $path): string
    {
        $process = $this->runAsAccount($this->commands()->entryType($path));
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
