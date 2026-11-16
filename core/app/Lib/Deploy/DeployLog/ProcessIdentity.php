<?php

namespace App\Lib\Deploy\DeployLog;

/**
 * Tells one process from the next one that reused its pid: a cancel kills the pid in latest.json, so its
 * start time is recorded alongside it and checked before any signal is sent.
 */
final class ProcessIdentity
{
    /** Field 22 of /proc/pid/stat, counting from the field after the comm. */
    private const START_TIME_FIELD = 19;

    /** Read after the last `)`: the parenthesised comm field may itself contain spaces. */
    public static function startTime(int $pid): ?string
    {
        if ($pid <= 0) {
            return null;
        }
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if (! is_string($stat)) {
            return null;
        }
        $end = strrpos($stat, ')');
        if ($end === false) {
            return null;
        }
        $fields = preg_split('/\s+/', trim(substr($stat, $end + 1))) ?: [];
        $startTime = $fields[self::START_TIME_FIELD] ?? '';

        return ctype_digit($startTime) ? $startTime : null;
    }

    public static function isStillRunning(mixed $pid, mixed $recordedStartTime): bool
    {
        return is_int($pid)
            && $pid > 0
            && is_string($recordedStartTime)
            && hash_equals($recordedStartTime, self::startTime($pid) ?? '');
    }
}
