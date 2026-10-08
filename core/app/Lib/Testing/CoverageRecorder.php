<?php

namespace App\Lib\Testing;

use Illuminate\Support\Facades\App;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use Throwable;

/**
 * Shared Xdebug coverage session used by HTTP CoverageMiddleware and job
 * RecordCoverage middleware. Dumps land in
 * {config testing.coverage_dir}/{tokenId}/dumps/ (the engine's tests/coverage).
 */
final class CoverageRecorder
{
    private static int $depth = 0;

    public static function reset(): void
    {
        self::$depth = 0;
    }

    public static function isActive(): bool
    {
        return self::$depth > 0;
    }

    public static function recordingPath(int $tokenId): string
    {
        return config('testing.coverage_dir') . "/{$tokenId}/recording";
    }

    public static function dumpsDir(int $tokenId): string
    {
        return config('testing.coverage_dir') . "/{$tokenId}/dumps";
    }

    public static function isRecording(int $tokenId): bool
    {
        return is_file(self::recordingPath($tokenId));
    }

    public static function canRecord(?int $tokenId): bool
    {
        return $tokenId !== null
            && App::hasDebugModeEnabled()
            && extension_loaded('xdebug')
            && self::isRecording($tokenId);
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function around(?int $tokenId, callable $work, string $dumpPrefix = 'cov'): mixed
    {
        if (!self::canRecord($tokenId) || self::$depth > 0) {
            return $work();
        }

        assert($tokenId !== null);

        self::$depth++;
        \xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);

        $result = null;
        $error = null;
        try {
            $result = $work();
        } catch (Throwable $e) {
            $error = $e;
        }

        $data = \xdebug_get_code_coverage();
        \xdebug_stop_code_coverage();
        self::$depth--;

        /** @psalm-suppress InternalClass, InternalMethod */
        $rawData = RawCodeCoverageData::fromXdebugWithoutPathCoverage($data);
        $dir = self::dumpsDir($tokenId);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = $dir . '/' . uniqid($dumpPrefix . '-', true) . '.rawcov';
        file_put_contents($file, serialize($rawData));

        if ($error !== null) {
            throw $error;
        }

        return $result;
    }
}
