<?php

namespace App\Lib\Host;

/**
 * Reads {@see HostMemory} off this host. MemTotal comes from /proc/meminfo,
 * which is not namespaced, so inside core it is the host's.
 */
final class HostMemoryProbe
{
    private static ?HostMemory $fake = null;

    public static function current(): HostMemory
    {
        if (self::$fake !== null) {
            return self::$fake;
        }

        return self::fromReadings((string) @file_get_contents('/proc/meminfo'), self::engineMb());
    }

    /** Tests: answer with this instead of probing. Null restores the probe. */
    public static function fake(?HostMemory $memory): void
    {
        self::$fake = $memory;
    }

    public static function fromReadings(string $procMeminfo, int $engineMb = HostMemory::ENGINE_DEFAULT_MB): HostMemory
    {
        $totalMb = preg_match('/^MemTotal:\s+(\d+)\s*kB/mi', $procMeminfo, $m) === 1 ? intdiv((int) $m[1], 1024) : 0;

        return new HostMemory($totalMb, $engineMb);
    }

    /** `DEPLOY_ENGINE_MEMORY`, in MB; 512 when unset. */
    private static function engineMb(): int
    {
        // Config is not loaded outside the app, e.g. in plain unit tests.
        $configured = app()->bound('config') ? (int) config('deploy.engine_memory', 0) : 0;

        return $configured > 0 ? $configured : HostMemory::ENGINE_DEFAULT_MB;
    }
}
