<?php

namespace App\Lib\Deploy\Dind;

use Illuminate\Support\Facades\Log;

/**
 * One host build at a time, whatever the queue is doing, since each may take half the host's RAM; the rest
 * of a deploy still runs QUEUE_WORKERS wide. Fails open after {@see WAIT_SECONDS}, with a line in the log.
 */
final class HostBuildSlot
{
    /** Long enough for a real build ahead of this one (PHP and Node host builds cap at 1800s and 3600s). */
    private const WAIT_SECONDS = 1800;

    private const POLL_MICROSECONDS = 250000;

    private const LOCK_FILE = 'host-build.lock';

    /**
     * Run `$build` with the host's build slot held, releasing it either way.
     *
     * @template T
     *
     * @param  callable(): T  $build
     * @param  ?callable(): void  $onWait  called once when the slot is busy
     * @return T
     */
    public static function run(callable $build, ?callable $onWait = null): mixed
    {
        $handle = self::acquire($onWait);

        try {
            return $build();
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    /**
     * Nowhere to put the lock is not a reason to refuse to build.
     *
     * @param  ?callable(): void  $onWait
     * @return resource|null the held lock, or null when the build proceeds unslotted
     */
    private static function acquire(?callable $onWait)
    {
        $path = self::lockPath();
        if ($path === null) {
            return null;
        }
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            Log::warning('Host build slot unavailable; building without it.');

            return null;
        }

        $deadline = microtime(true) + self::WAIT_SECONDS;
        $waited = false;
        while (! flock($handle, LOCK_EX | LOCK_NB)) {
            if (! $waited) {
                $waited = true;
                if ($onWait !== null) {
                    $onWait();
                }
            }
            if (microtime(true) >= $deadline) {
                fclose($handle);
                Log::warning('Host build slot still held after '.self::WAIT_SECONDS.'s; building anyway.');

                return null;
            }
            usleep(self::POLL_MICROSECONDS);
        }

        return $handle;
    }

    /** Null outside a booted application (plain unit tests), which builds unslotted. */
    private static function lockPath(): ?string
    {
        try {
            return storage_path(self::LOCK_FILE);
        } catch (\Throwable) {
            return null;
        }
    }
}
