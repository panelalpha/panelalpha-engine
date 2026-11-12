<?php

namespace App\Lib\Deploy\DeployLog;

use App\System;
use App\System\Privileged;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Writes under storage/logs/deploy, retrying once after a chown when the directory belongs to someone else,
 * since the engine runs as both www-data and root (as CustomRotatingFileHandler does).
 */
final class LogStorage
{
    private const DIRECTORY_MODE = 0775;

    private const LOW_DISK_BYTES = 1048576;

    private const OWNER = 'www-data:www-data';

    public static function ensureDirectory(string $dir): void
    {
        if (is_dir($dir) && is_writable($dir)) {
            return;
        }
        is_dir($dir) ? self::makeWritable($dir) : self::create($dir);
    }

    public static function write(string $path, string $contents, int $flags = 0): void
    {
        self::ensureDirectory(dirname($path));
        if (@file_put_contents($path, $contents, $flags) !== false) {
            return;
        }

        self::fixOwnership(dirname($path));
        if (@file_put_contents($path, $contents, $flags) === false) {
            throw new RuntimeException(
                'Could not write deploy log file: '.$path.self::writeFailureHint($path)
            );
        }
    }

    /** Replace $path atomically, so a reader never sees a half-written file. */
    public static function replace(string $path, string $contents): void
    {
        $temporary = $path.'.tmp.'.getmypid();
        self::write($temporary, $contents);
        if (@rename($temporary, $path)) {
            return;
        }

        self::fixOwnership(dirname($path));
        if (! @rename($temporary, $path)) {
            throw new RuntimeException("Could not write deploy latest pointer: {$path}");
        }
    }

    private static function create(string $dir): void
    {
        if (@mkdir($dir, self::DIRECTORY_MODE, true) || is_dir($dir)) {
            return;
        }

        self::fixOwnership(dirname($dir));
        if (! @mkdir($dir, self::DIRECTORY_MODE, true) && ! is_dir($dir)) {
            throw new RuntimeException(
                'Could not create deploy log directory: '.$dir.self::writeFailureHint($dir)
            );
        }
    }

    private static function makeWritable(string $dir): void
    {
        self::fixOwnership($dir);
        if (! is_writable($dir)) {
            throw new RuntimeException("Deploy log directory is not writable: {$dir}");
        }
    }

    /**
     * The hint a failed write's exception carries: it says when the disk is full, so
     * {@see DeployFailureExplainer} can turn it into a sentence the customer can act on.
     */
    private static function writeFailureHint(string $path): string
    {
        $last = error_get_last();
        $detail = is_array($last) ? (string) ($last['message'] ?? '') : '';
        $free = @disk_free_space(is_dir($path) ? $path : dirname($path));

        $outOfSpace = stripos($detail, 'no space') !== false
            || (is_numeric($free) && (int) $free < self::LOW_DISK_BYTES);

        return $outOfSpace ? ': no space left on device' : '';
    }

    private static function fixOwnership(string $path): void
    {
        if ($path === '' || $path === '/') {
            return;
        }

        try {
            (new System)->runProcess(Privileged::files()->changeOwnerRecursively(self::OWNER, $path));
        } catch (\Throwable $e) {
            Log::warning('DeployLogger: could not fix ownership', [
                'path' => $path,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
