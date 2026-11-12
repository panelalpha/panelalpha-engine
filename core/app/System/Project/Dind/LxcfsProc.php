<?php

namespace App\System\Project\Dind;

use App\System;
use App\System\Privileged;
use App\System\Privileged\Scripts\HostReadings;
use App\System\Project\Dind as DindProject;
use Illuminate\Support\Facades\Log;

/**
 * The host's lxcfs files bound over the /proc of the account and every container it runs, so memory, CPUs and load
 * read as their own; nothing is mounted when lxcfs is not running.
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
     * @param  list<string>  $present  file names found in {@see PROC_DIR}
     * @return list<string>
     */
    public static function mounts(array $present, bool $sysbox): array
    {
        return array_values(array_filter(
            self::FILES,
            static fn (string $file): bool => in_array($file, $present, true)
                && ! ($sysbox && in_array($file, self::SYSBOX_VIRTUALISED, true))
        ));
    }

    /**
     * The files the account's containers can bind from {@see PROC_DIR}: an account created before the template
     * mounted it, or while lxcfs was down, has none, and a missing bind source would fail the deploy.
     *
     * @return list<string>
     */
    public static function inAccount(DindProject $dind): array
    {
        try {
            $output = $dind->shell()->execQuiet(['sh', '-c', (new HostReadings)->lxcfsFiles()], [], 30);
        } catch (\Throwable) {
            return [];
        }

        return self::mounts(
            array_values(array_intersect(self::FILES, preg_split('/\s+/', trim($output)) ?: [])),
            AccountRuntime::isSysbox()
        );
    }

    /**
     * Which of {@see FILES} the host's lxcfs serves right now. Empty, and
     * logged, when lxcfs is not installed or not running.
     *
     * @return list<string>
     */
    public static function present(System $system): array
    {
        try {
            $output = $system->exec(Privileged::host()->lxcfsFiles());
        } catch (\Throwable $e) {
            Log::warning('Could not look for lxcfs on the host; the account sees the host\'s /proc: '.$e->getMessage());

            return [];
        }

        $present = array_values(array_intersect(self::FILES, preg_split('/\s+/', trim($output)) ?: []));
        if ($present === []) {
            Log::warning('lxcfs is not running on the host ('.self::PROC_DIR.' is empty); '
                .'the account sees the host\'s memory, CPUs and load in /proc.');
        }

        return $present;
    }
}
