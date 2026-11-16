<?php

namespace App\Lib\Deploy\Telemetry;

use Illuminate\Support\Facades\Log;

/**
 * The zip of an account's source, written in {@see Telemetry::captureDeploy()} while the tree exists, by the rules of
 * {@see SourceBundlePolicy}. A tree over a cap gets no bundle at all: a truncated repository is a misleading report.
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
     * Why no bundle can be made, before anything is walked or written: "there was no source" is a truer
     * answer than "this box has no zip extension", so it is asked first.
     */
    private static function unavailableReason(string $projectDir): ?string
    {
        if (! is_dir($projectDir)) {
            return self::SKIP_EMPTY;
        }

        return class_exists(\ZipArchive::class) ? null : self::SKIP_UNAVAILABLE;
    }

    /**
     * {@see plan()} with its failures turned into skip reasons: a walk that throws is a telemetry problem,
     * never a deploy problem, so it is logged rather than raised.
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
            Log::debug('Telemetry source bundle walk failed: '.$e->getMessage());

            return ['skipped' => self::SKIP_ERROR];
        }

        if (isset($selection['skipped'])) {
            return $selection;
        }

        return $selection['files'] === [] ? ['skipped' => self::SKIP_EMPTY] : $selection;
    }

    /**
     * Write the archive: null on success, a skip reason otherwise. A half-written zip is removed, or the shipper
     * would upload it as though it were the source.
     *
     * @param  array<string, string>  $files  relative path => absolute path
     */
    private static function writeZip(array $files, string $zipPath): ?string
    {
        $zip = new \ZipArchive;
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
            Log::debug('Telemetry source bundle write failed: '.$e->getMessage());

            return self::SKIP_ERROR;
        }

        return is_file($zipPath) ? null : self::SKIP_ERROR;
    }

    /**
     * A tree of already-compressed assets can come out larger than the uncompressed budget allowed for:
     * such a bundle is deleted, not shipped.
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
     * Exactly which files would go into a bundle, without writing one, so "what would you send" is answerable
     * without sending it. Excluded directories are pruned during the walk, never stat-ed and thrown away.
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
     * Every file under $root worth looking at, excluded directories pruned during recursion, and never a
     * symlink: it can point anywhere, out of the account's home directory included.
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
                if ($file->isLink()) {
                    return false;
                }

                return ! ($file->isDir() && SourceBundlePolicy::isExcludedDirectory($file->getFilename()));
            }
        ), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD);
    }

    /**
     * The path this file would have inside the bundle, or null when it does not belong in one. Readability is
     * checked last, as the one test that touches the filesystem again.
     */
    private static function includedPath(\SplFileInfo $file, int $rootLength, int $maxFileBytes): ?string
    {
        if (! $file->isFile() || $file->isLink()) {
            return null;
        }

        $relative = substr($file->getPathname(), $rootLength);
        if ($relative === false || $relative === '') {
            return null;
        }
        if (! SourceBundlePolicy::shouldInclude($relative, (int) $file->getSize(), $maxFileBytes)) {
            return null;
        }

        return $file->isReadable() ? $relative : null;
    }
}
