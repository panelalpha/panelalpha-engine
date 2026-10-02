<?php

namespace App\System\Project\Dind;

use App\System;
use Illuminate\Support\Facades\Log;

/**
 * The host's lxcfs files bound over the account's /proc, so memory, CPUs and
 * load read as the account's own. Nothing is mounted when lxcfs is not running.
 */
final class LxcfsProc
{
    public const PROC_DIR = '/var/lib/lxcfs/proc';

    /** What the account template mounts, in this order, when lxcfs provides it. */
    public const FILES = ['meminfo', 'cpuinfo', 'stat', 'loadavg', 'diskstats', 'uptime', 'swaps'];

    /** sysbox-fs already mounts its own over these two; a second FUSE layer is not wanted. */
    public const SYSBOX_VIRTUALISED = ['uptime', 'swaps'];

    /**
     * The files the account template binds over /proc.
     *
     * @param list<string> $present file names found in {@see PROC_DIR}
     * @return list<string>
     */
    public static function mounts(array $present, bool $sysbox): array
    {
        return array_values(array_filter(
            self::FILES,
            static fn (string $file): bool => in_array($file, $present, true)
                && !($sysbox && in_array($file, self::SYSBOX_VIRTUALISED, true))
        ));
    }

    /**
     * Which of {@see FILES} the host's lxcfs serves right now. Empty, and
     * logged, when lxcfs is not installed or not running.
     *
     * @return list<string>
     */
    public static function present(System $system): array
    {
        $script = 'for f in ' . implode(' ', self::FILES) . '; do [ -f ' . self::PROC_DIR . '/$f ] && echo "$f"; done; true';
        try {
            $output = $system->execOnHost(['sh', '-c', $script]);
        } catch (\Throwable $e) {
            Log::warning('Could not look for lxcfs on the host; the account sees the host\'s /proc: ' . $e->getMessage());

            return [];
        }

        $present = array_values(array_intersect(self::FILES, preg_split('/\s+/', trim($output)) ?: []));
        if ($present === []) {
            Log::warning('lxcfs is not running on the host (' . self::PROC_DIR . ' is empty); '
                . 'the account sees the host\'s memory, CPUs and load in /proc.');
        }

        return $present;
    }
}
