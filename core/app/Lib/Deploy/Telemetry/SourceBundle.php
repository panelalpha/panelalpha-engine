<?php

namespace App\Lib\Deploy\Telemetry;

use Illuminate\Support\Facades\Log;

/**
 * Build the zip of an account's application source.
 *
 * Runs inside {@see Telemetry::captureDeploy()}, which is called from
 * `DeployLogger::finish()` — and that timing is not incidental. A failed
 * account creation is rolled back by `UserController::deleteFailedAccount()`
 * immediately after the log is finished, so by the time the shipper runs five
 * minutes later the source is gone. If a bundle is going to exist at all it has
 * to be written while the tree is still on disk.
 *
 * What comes out is capped three ways — total bytes, file count, per-file bytes
 * — and if the tree busts a cap after exclusions, no bundle is produced at all.
 * A truncated repository is a misleading bug report; saying "too large" is an
 * honest one.
 *
 * Selection rules live in {@see SourceBundlePolicy} so they can be tested
 * without a filesystem.
 */
class SourceBundle
{
    public const SKIP_TOO_LARGE = 'too-large';
    public const SKIP_TOO_MANY_FILES = 'too-many-files';
    public const SKIP_EMPTY = 'empty';
    public const SKIP_UNAVAILABLE = 'zip-unavailable';
    public const SKIP_ERROR = 'error';

    /**
     * @return array{
     *   available: bool, files?: int, bytes?: int, sha256?: string, skipped?: string
     * }
     */
    public static function create(
        string $projectDir,
        string $zipPath,
        int $maxBytes,
        int $maxFiles,
        int $maxFileBytes
    ): array {
        $blocked = self::unavailableReason($projectDir);
        if ($blocked !== null) {
            return self::skipped($blocked);
        }

        $selection = self::selectFiles($projectDir, $maxBytes, $maxFiles, $maxFileBytes);
        if (isset($selection['skipped'])) {
            return self::skipped($selection['skipped']);
        }

        $written = self::writeZip($selection['files'], $zipPath);
        if ($written !== null) {
            return self::skipped($written);
        }

        $rejected = self::rejectOversized($zipPath, $maxBytes);
        if ($rejected !== null) {
            return self::skipped($rejected);
        }

        return [
            'available' => true,
            'files' => count($selection['files']),
            'bytes' => (int) filesize($zipPath),
            'sha256' => (string) hash_file('sha256', $zipPath),
        ];
    }

    /**
     * Why no bundle can be made, before anything is walked or written.
     *
     * Order matters for the reason reported: "there was no source" is a truer
     * answer than "this box has no zip extension", and is_dir() is cheap
     * enough to ask first.
     */
    private static function unavailableReason(string $projectDir): ?string
    {
        if (!is_dir($projectDir)) {
            return self::SKIP_EMPTY;
        }

        return class_exists(\ZipArchive::class) ? null : self::SKIP_UNAVAILABLE;
    }

    /**
     * {@see plan()} with its failures turned into skip reasons.
     *
     * A tree walk that throws is a telemetry problem and never a deploy
     * problem, so it is logged and reported as a skip rather than raised.
     *
     * @return array{files: array<string, string>, uncompressed: int}|array{skipped: string}
     */
    private static function selectFiles(
        string $projectDir,
        int $maxBytes,
        int $maxFiles,
        int $maxFileBytes
    ): array {
        try {
            $selection = self::plan($projectDir, $maxBytes, $maxFiles, $maxFileBytes);
        } catch (\Throwable $e) {
            Log::debug('Telemetry source bundle walk failed: ' . $e->getMessage());

            return ['skipped' => self::SKIP_ERROR];
        }

        if (isset($selection['skipped'])) {
            return $selection;
        }

        return $selection['files'] === [] ? ['skipped' => self::SKIP_EMPTY] : $selection;
    }

    /**
     * Write the archive. Null on success, a skip reason otherwise.
     *
     * A half-written zip is removed rather than left for the shipper to find:
     * it would be uploaded as though it were the source.
     *
     * @param array<string, string> $files relative path => absolute path
     */
    private static function writeZip(array $files, string $zipPath): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return self::SKIP_ERROR;
        }

        try {
            foreach ($files as $relative => $absolute) {
                $zip->addFile($absolute, $relative);
            }
            $zip->close();
        } catch (\Throwable $e) {
            @$zip->close();
            @unlink($zipPath);
            Log::debug('Telemetry source bundle write failed: ' . $e->getMessage());

            return self::SKIP_ERROR;
        }

        return is_file($zipPath) ? null : self::SKIP_ERROR;
    }

    /**
     * Compression usually wins, but a tree of already-compressed assets can
     * come out larger than the uncompressed budget allowed for. Such a bundle
     * is deleted, not shipped.
     */
    private static function rejectOversized(string $zipPath, int $maxBytes): ?string
    {
        if ((int) filesize($zipPath) <= $maxBytes) {
            return null;
        }
        @unlink($zipPath);

        return self::SKIP_TOO_LARGE;
    }

    /**
     * @return array{available: false, skipped: string}
     */
    private static function skipped(string $reason): array
    {
        return ['available' => false, 'skipped' => $reason];
    }

    /**
     * Exactly which files would go into a bundle, without writing one.
     *
     * Public because "what would you send" has to be answerable without
     * sending it — and because it is the half of this class that can be tested
     * on a machine with no ext-zip.
     *
     * Excluded directories are pruned during recursion rather than filtered
     * afterwards: `node_modules` in a real project is hundreds of thousands of
     * inodes, and stat-ing all of them to throw them away would put seconds of
     * pointless I/O into a deploy request.
     *
     * @return array{files: array<string, string>, uncompressed: int}|array{skipped: string}
     */
    public static function plan(
        string $projectDir,
        int $maxBytes,
        int $maxFiles,
        int $maxFileBytes
    ): array {
        $root = rtrim($projectDir, '/');
        $rootLength = strlen($root) + 1;

        $files = [];
        $uncompressed = 0;

        foreach (self::walk($root) as $file) {
            $relative = self::includedPath($file, $rootLength, $maxFileBytes);
            if ($relative === null) {
                continue;
            }

            if (count($files) >= $maxFiles) {
                return ['skipped' => self::SKIP_TOO_MANY_FILES];
            }
            $uncompressed += (int) $file->getSize();
            if ($uncompressed > $maxBytes) {
                return ['skipped' => self::SKIP_TOO_LARGE];
            }

            $files[$relative] = $file->getPathname();
        }

        return ['files' => $files, 'uncompressed' => $uncompressed];
    }

    /**
     * Every file under $root worth looking at, excluded directories pruned
     * during recursion rather than after.
     *
     * @return \Iterator<\SplFileInfo>
     */
    private static function walk(string $root): \Iterator
    {
        $directories = new \RecursiveDirectoryIterator(
            $root,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
        );

        return new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            $directories,
            static function (\SplFileInfo $file): bool {
                // A symlink can point anywhere, including out of the account's
                // home directory. Never follow one into the bundle.
                if ($file->isLink()) {
                    return false;
                }

                return !($file->isDir() && SourceBundlePolicy::isExcludedDirectory($file->getFilename()));
            }
        ), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD);
    }

    /**
     * The path this file would have inside the bundle, or null when it does
     * not belong in one.
     *
     * Readability is checked last: it is the only test here that touches the
     * filesystem again, and most files are rejected by policy before it.
     */
    private static function includedPath(\SplFileInfo $file, int $rootLength, int $maxFileBytes): ?string
    {
        if (!$file->isFile() || $file->isLink()) {
            return null;
        }

        $relative = substr($file->getPathname(), $rootLength);
        if ($relative === false || $relative === '') {
            return null;
        }
        if (!SourceBundlePolicy::shouldInclude($relative, (int) $file->getSize(), $maxFileBytes)) {
            return null;
        }

        return $file->isReadable() ? $relative : null;
    }
}
