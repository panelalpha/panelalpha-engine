<?php

namespace App\Lib\Host;

use App\System;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads {@see HostMemory} off this host, live.
 *
 * MemTotal and MemAvailable come from /proc/meminfo, which is not namespaced,
 * so inside core they are the host's. Each container's memory comes from its
 * cgroup on the host: anon + shmem + kernel, what it cannot hand back, so page
 * cache the kernel would reclaim does not count. A container belongs to the
 * engine when its compose working directory is the engine's; every other
 * container is a project's. Two short commands, so nothing is cached.
 */
final class HostMemoryProbe
{
    private const TIMEOUT = 30;

    private const WORKING_DIR_LABEL = 'com.docker.compose.project.working_dir';

    /** Per container cgroup, systemd driver first, then cgroupfs: "<id>\t<bytes>". */
    private const CGROUP_SCRIPT = <<<'SH'
        for d in /sys/fs/cgroup/system.slice/docker-*.scope /sys/fs/cgroup/docker/*; do
          [ -f "$d/memory.stat" ] || continue
          id=$(basename "$d"); id=${id#docker-}; id=${id%.scope}
          awk -v id="$id" '$1=="anon"||$1=="shmem"||$1=="kernel"{s+=$2} END{printf "%s\t%d\n", id, s}' "$d/memory.stat"
        done
        SH;

    private static ?HostMemory $fake = null;

    public static function current(): HostMemory
    {
        if (self::$fake !== null) {
            return self::$fake;
        }

        $system = new System();
        $meminfo = (string) @file_get_contents('/proc/meminfo');
        try {
            $ps = $system->exec(
                ['sudo', 'docker', 'ps', '--no-trunc', '--format', '{{.ID}}\t{{.Label "' . self::WORKING_DIR_LABEL . '"}}'],
                [],
                self::TIMEOUT
            );
            $cgroups = $system->execOnHost(['sh', '-c', self::CGROUP_SCRIPT]);
        } catch (Throwable $e) {
            // Unreadable containers count as nothing: the pool then rests on MemAvailable alone.
            Log::warning('Could not measure container memory: ' . $e->getMessage());
            $ps = $cgroups = '';
        }

        return self::fromReadings($meminfo, $ps, $cgroups, $system->engineDirPath(), self::configuredPoolMb());
    }

    /** Tests: answer with this instead of probing. Null restores the probe. */
    public static function fake(?HostMemory $memory): void
    {
        self::$fake = $memory;
    }

    /**
     * @param string $ps `docker ps --no-trunc` lines of "<id>\t<working dir>"
     * @param string $cgroups {@see CGROUP_SCRIPT} lines of "<id>\t<bytes>"
     */
    public static function fromReadings(
        string $procMeminfo,
        string $ps,
        string $cgroups,
        string $engineDir,
        ?int $configuredPoolMb = null
    ): HostMemory {
        $engineIds = [];
        foreach (self::lines($ps) as [$id, $workingDir]) {
            if ($workingDir !== '' && rtrim($workingDir, '/') === rtrim($engineDir, '/')) {
                $engineIds[$id] = true;
            }
        }

        $engine = 0;
        $projects = 0;
        foreach (self::lines($cgroups) as [$id, $bytes]) {
            if (!ctype_digit($bytes)) {
                continue;
            }
            if (isset($engineIds[$id])) {
                $engine += (int) $bytes;
            } else {
                $projects += (int) $bytes;
            }
        }

        return new HostMemory(
            self::meminfoMb($procMeminfo, 'MemTotal'),
            self::meminfoMb($procMeminfo, 'MemAvailable'),
            intdiv($engine, 1048576),
            intdiv($projects, 1048576),
            $configuredPoolMb
        );
    }

    /** `DEPLOY_PROJECTS_MEMORY`, in MB, when the operator set one. */
    private static function configuredPoolMb(): ?int
    {
        $configured = (int) config('deploy.projects_memory', 0);

        return $configured > 0 ? $configured : null;
    }

    private static function meminfoMb(string $meminfo, string $field): int
    {
        return preg_match('/^' . $field . ':\s+(\d+)\s*kB/mi', $meminfo, $m) === 1 ? intdiv((int) $m[1], 1024) : 0;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function lines(string $output): array
    {
        $rows = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $parts = explode("\t", trim($line), 2);
            if ($parts[0] !== '') {
                $rows[] = [$parts[0], trim($parts[1] ?? '')];
            }
        }

        return $rows;
    }
}
