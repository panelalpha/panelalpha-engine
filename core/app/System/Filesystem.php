<?php

namespace App\System;

use App\System;
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
    /** Root-only directory beside the account homes where a write into a home is staged, owned and moded. */
    public const WRITE_STAGE_NAME = '.panelalpha-write-stage';

    /** Reads and renames a file in a home without following a link; relative to core/. */
    private const ACCOUNT_HOME_FILE = '/resources/system/account-home-file.py';

    /** Its exit code when a directory on the path is a symlink, or the file itself is one. */
    private const ACCOUNT_HOME_FILE_REFUSED = 3;

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
     * temp file, then `mv -T` over $path, so a symlink at $path (one a tenant
     * could plant) is replaced, never followed or entered. Inside an account's
     * home the temp file is owned and moded in the root-only write stage, so
     * no chown or chmod ever names a path the tenant can swap for a link.
     */
    public function writeFileReplacingPath(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
    {
        $this->makeDirWithParents(dirname($path), $chown);
        $staged = $this->stagedPathFor($path);
        try {
            $this->copyContents($contents, $staged);
            $this->setOwnerAndMode($staged, $chown, $chmod);
            $this->renameOnto($staged, $path);
        } catch (\Throwable $e) {
            $this->system->runProcess(['sudo', 'rm', '-f', $staged]);
            throw $e;
        }
    }

    /** A temp name in the write stage for a path in an account's home, else one next to $path. */
    private function stagedPathFor(string $path): string
    {
        if (! $this->isInAccountHome($path)) {
            return $path.'.tmp-'.bin2hex(random_bytes(8));
        }

        return $this->writeStageFor(dirname($path)).'/'.bin2hex(random_bytes(8));
    }

    private function isInAccountHome(string $path): bool
    {
        $homes = rtrim($this->homesDir(), '/').'/';

        return str_starts_with($path, $homes) && ! str_starts_with($path, $this->writeStage().'/') && $path !== $this->writeStage();
    }

    private function homesDir(): string
    {
        return $this->system instanceof System ? $this->system->homesDirPath() : EnginePaths::HOMES_DIR;
    }

    private function writeStage(): string
    {
        return rtrim($this->homesDir(), '/').'/'.self::WRITE_STAGE_NAME;
    }

    /**
     * The write stage, once it is known to share a filesystem with $dir: only
     * then is `mv -T` a rename. Anything else is refused, never copied across.
     */
    private function writeStageFor(string $dir): string
    {
        $stage = $this->writeStage();
        $this->system->exec(['sudo', 'mkdir', '-p', '-m', '0700', $stage]);
        $devices = $this->system->runProcess(['sudo', 'stat', '-c', '%d', $stage, $dir]);
        $lines = preg_split('/\R/', trim($devices->getOutput())) ?: [];
        if ($devices->getExitCode() !== 0 || count($lines) !== 2 || ! ctype_digit($lines[0]) || $lines[0] !== $lines[1]) {
            throw new \RuntimeException("Refusing to write into '{$dir}': it is not on the filesystem of {$stage}, so the file cannot be renamed into place.");
        }

        return $stage;
    }

    /**
     * Rename $staged onto $path. Inside an account's home every directory above
     * $path may be one the tenant can swap for a symlink, or a `..` it chose,
     * which would carry the rename into another account's tree: the path must
     * stay in the account home it names, and {@see ACCOUNT_HOME_FILE} walks it
     * without following a link and renames in the same system calls.
     */
    private function renameOnto(string $staged, string $path): void
    {
        if (! $this->isInAccountHome($path)) {
            $this->system->exec(['sudo', 'mv', '-T', $staged, $path]);

            return;
        }
        if (! $this->staysInTheAccountHomeItNames($path)) {
            throw $this->renameRefused($path);
        }
        $renamed = $this->system->runProcess($this->accountHomeFileArgv('rename', rtrim($this->homesDir(), '/'), $staged, self::lexicalPath($path)));
        if ($renamed->getExitCode() === self::ACCOUNT_HOME_FILE_REFUSED) {
            throw $this->renameRefused($path);
        }
        if ($renamed->getExitCode() !== 0) {
            throw new \RuntimeException("Cannot move '{$staged}' to '{$path}': ".trim($renamed->getErrorOutput()));
        }
    }

    private function staysInTheAccountHomeItNames(string $path): bool
    {
        $homes = rtrim($this->homesDir(), '/');
        $dir = self::lexicalPath(dirname($path));
        $account = explode('/', substr($path, strlen($homes) + 1), 2)[0];

        return $dir === $homes
            || (! in_array($account, ['', '.', '..'], true) && str_starts_with($dir.'/', "{$homes}/{$account}/"));
    }

    private function renameRefused(string $path): \RuntimeException
    {
        return new \RuntimeException("Refusing to write '{$path}': a directory above it is a symlink or leads out of the account's home.");
    }

    /**
     * @param  string  $root  the directory the walk starts from, as the host has it
     * @return list<string>
     */
    private function accountHomeFileArgv(string $operation, string $root, string ...$paths): array
    {
        return ['sudo', 'python3', '-I', dirname(__DIR__, 2).self::ACCOUNT_HOME_FILE, $operation, $root, ...$paths];
    }

    /** $path with `.`, `..` and repeated slashes resolved by name alone, nothing on disk followed. */
    private static function lexicalPath(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return '/'.implode('/', $parts);
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

    /**
     * The refusal a write to $path would end in, for a step that has to come before
     * that write: a directory between $root and $path that is a symlink.
     */
    public function assertNoSymlinkAbove(string $path, string $root): void
    {
        $root = self::lexicalPath($root);
        for ($dir = dirname(self::lexicalPath($path)); str_starts_with($dir, $root.'/'); $dir = dirname($dir)) {
            if ($this->isSymlink($dir)) {
                throw $this->renameRefused($path);
            }
        }
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
            $this->isInAccountHome($d) ? $this->makeDirReplacingPath($d, $chown) : $this->makeDir($d, $chown);
        }
    }

    private function makeDir(string $dir, ?string $chown): void
    {
        $this->system->exec(['sudo', 'mkdir', $dir]);
        $this->setOwnerAndMode($dir, $chown, null);
    }

    /** Made and owned in the write stage, then renamed into the home, like a file from writeFileReplacingPath(). */
    private function makeDirReplacingPath(string $dir, ?string $chown): void
    {
        $staged = $this->writeStageFor(dirname($dir)).'/'.bin2hex(random_bytes(8));
        try {
            $this->makeDir($staged, $chown);
            $this->renameOnto($staged, $dir);
        } catch (\Throwable $e) {
            $this->system->runProcess(['sudo', 'rm', '-rf', $staged]);
            throw $e;
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
        if ($this->isInAccountHome($path)) {
            return $this->readInsideAccountHome($path);
        }
        $tmpFile = tempnam(sys_get_temp_dir(), 'tmp_');

        try {
            $this->system->exec(['sudo', 'cp', $path, $tmpFile]);

            return (string) file_get_contents($tmpFile);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Read through stdout, never into a temp file: root may not open a file
     * www-data owns in the sticky /tmp when fs.protected_regular is set.
     */
    private function readInsideAccountHome(string $path): string
    {
        $read = $this->system->runProcess($this->accountHomeReadArgv($path));
        if ($read->getExitCode() !== 0) {
            throw new \RuntimeException("Cannot read '{$path}': ".trim($read->getErrorOutput()));
        }

        return $read->getOutput();
    }

    /**
     * $path resolved, then read by {@see ACCOUNT_HOME_FILE} from inside the
     * resolved home without following a link, so a directory swapped for one
     * after the check fails the read.
     *
     * @return list<string>
     */
    private function accountHomeReadArgv(string $path): array
    {
        [$home, $resolved] = $this->realPathInsideAccountHome($path);

        return $this->accountHomeFileArgv('read', $home, $resolved);
    }

    /**
     * The account home and $path with every symlink and `..` resolved, refused
     * unless the path is still inside that home: as root, a tenant's link would
     * otherwise hand the engine another account's file to copy into its own.
     *
     * @return array{0: string, 1: string}
     */
    private function realPathInsideAccountHome(string $path): array
    {
        $home = $this->accountHomeOf($path);
        $resolved = $this->system->runProcess(['sudo', 'realpath', '-e', '--', $home, $path]);
        $lines = preg_split('/\R/', trim($resolved->getOutput())) ?: [];
        if ($resolved->getExitCode() !== 0) {
            throw new \RuntimeException("Cannot read '{$path}': ".trim($resolved->getErrorOutput() ?: 'it does not exist.'));
        }
        if (count($lines) !== 2 || ! str_starts_with($lines[1], rtrim($lines[0], '/').'/')) {
            throw new \RuntimeException("Refusing to read '{$path}': it resolves to a file outside {$home}.");
        }

        return [rtrim($lines[0], '/'), $lines[1]];
    }

    private function accountHomeOf(string $path): string
    {
        $homes = rtrim($this->homesDir(), '/');
        $account = explode('/', substr($path, strlen($homes) + 1), 2)[0];
        if (in_array($account, ['', '.', '..'], true)) {
            throw new \RuntimeException("Refusing to read '{$path}': it names no account home.");
        }

        return $homes.'/'.$account;
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
        if (! $this->isInAccountHome($path)) {
            return $this->outputOrNull(['sudo', 'cat', $path]);
        }
        try {
            return $this->outputOrNull($this->accountHomeReadArgv($path));
        } catch (\RuntimeException) {
            return null;
        }
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
