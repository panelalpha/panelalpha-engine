<?php

namespace App\Lib\Hub;

use App\Models\Setting;

/**
 * The address PanelAlpha Connect shows for this engine. The Hub never connects to an engine
 * (it reports by push), so any valid IP will do, a private one behind NAT too; a host name is
 * refused here rather than after a round trip.
 */
final class EngineAddress
{
    public static function default(): ?string
    {
        try {
            $ip = trim((string) Setting::get('default_ipv4'));
        } catch (\Throwable) {
            return null;
        }

        return $ip === '' ? null : $ip;
    }

    /** Null when it is an IP address, else why not. */
    public static function problem(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return "'{$ip}' is not an IP address. Pass the engine's address with --ip.";
        }

        return null;
    }
}
