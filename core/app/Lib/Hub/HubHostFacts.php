<?php

namespace App\Lib\Hub;

use App\Lib\Deploy\Telemetry\HostFacts;
use App\Models\HubConnection;
use App\System;
use Illuminate\Support\Carbon;

/**
 * The host facts a report sends, measured at most once an hour and kept on the connection: a report reads what is
 * stored, never the host. Uptime is kept as the boot time, so it keeps counting between measurements.
 */
final class HubHostFacts
{
    private const KEYS = ['hostname', 'os', 'kernel', 'cpu_cores', 'memory_total_bytes', 'disk_total_bytes', 'ipv4'];

    private const REFRESH_MINUTES = 60;

    public static function refreshIfStale(HubConnection $connection, ?System $system = null): void
    {
        if ($connection->host_facts_at !== null && $connection->host_facts_at->gt(now()->subMinutes(self::REFRESH_MINUTES))) {
            return;
        }

        $measured = HostFacts::host($system ?? new System);
        $facts = array_intersect_key($measured, array_flip(self::KEYS));
        $uptime = $measured['uptime_seconds'] ?? null;
        $facts['booted_at'] = is_int($uptime) ? now()->getTimestamp() - $uptime : null;

        $connection->forceFill(['host_facts' => $facts, 'host_facts_at' => now()])->save();
    }

    /** @return array<string, mixed> the eight keys of a report's `host`, null where nothing was measured */
    public static function of(HubConnection $connection, ?Carbon $now = null): array
    {
        $facts = $connection->host_facts ?? [];
        $host = [];
        foreach (self::KEYS as $key) {
            $host[$key] = $facts[$key] ?? null;
        }
        $bootedAt = $facts['booted_at'] ?? null;
        $host['uptime_seconds'] = is_int($bootedAt) ? max(0, ($now ?? now())->getTimestamp() - $bootedAt) : null;

        return $host;
    }
}
