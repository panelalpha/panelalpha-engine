<?php

namespace App\Lib\Task;

use Illuminate\Support\Facades\Log;

/**
 * Caps how many task log streams run at once.
 *
 * A stream holds a php-fpm worker for the life of its task and core's pool has
 * five, so one is always left for the API and the password gate. A slot is a
 * flock()ed file: the kernel releases it when a killed worker cannot.
 */
final class LogStreamLimiter
{
    /** One below core's five php-fpm workers. */
    public const MAX_CONCURRENT = 4;

    public const MESSAGE = 'Too many task log streams are open. Close one and retry, or poll GET /tasks/{id}/logs instead.';

    public static function directory(): string
    {
        return storage_path('framework/task-log-streams');
    }

    /**
     * A free slot, held until release(), or null when every one is in use. When the
     * directory cannot be created nothing can be counted, so nothing is refused either.
     *
     * @return resource|null
     */
    public static function acquire(int $limit = self::MAX_CONCURRENT)
    {
        $dir = self::directory();

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            Log::warning('Could not create the task log stream limiter directory', ['path' => $dir]);

            return fopen('php://memory', 'r+') ?: null;
        }

        for ($slot = 0; $slot < $limit; $slot++) {
            $handle = self::openSlot($dir, $slot);
            if ($handle === null) {
                continue;
            }

            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }

            fclose($handle);
        }

        return null;
    }

    /**
     * @param resource|null $handle
     */
    public static function release($handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /** How many slots are in use right now. */
    public static function active(int $limit = self::MAX_CONCURRENT): int
    {
        $dir = self::directory();
        if (!is_dir($dir)) {
            return 0;
        }

        $used = 0;
        for ($slot = 0; $slot < $limit; $slot++) {
            $handle = self::openSlot($dir, $slot);
            if ($handle === null) {
                continue;
            }

            if (self::isHeld($handle)) {
                $used++;
            }

            fclose($handle);
        }

        return $used;
    }

    /** @return resource|null */
    private static function openSlot(string $dir, int $slot)
    {
        $handle = @fopen($dir . '/slot-' . $slot, 'c');

        return $handle === false ? null : $handle;
    }

    /**
     * Whether another process holds the slot; a free one is locked and released again to tell.
     *
     * @param resource $handle
     */
    private static function isHeld($handle): bool
    {
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            return true;
        }
        @flock($handle, LOCK_UN);

        return false;
    }
}
