<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Dind\DindImageStore;

/**
 * The exclusive side of {@see DindImageStore::PUSH_LOCK}: deleting tags and
 * garbage-collecting while no push is uploading layers a GC would delete.
 */
final class CacheRegistryLock
{
    /**
     * Run $work holding the lock exclusively. False when the lock could not be
     * taken, in which case $work did not run.
     *
     * @param  callable(): void  $work
     */
    public static function exclusive(callable $work): bool
    {
        $path = DindImageStore::pushLock();
        if (! file_exists($path)) {
            @touch($path);
        }
        $lock = @fopen($path, 'r');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            return false;
        }

        try {
            $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return true;
    }
}
