<?php

namespace App\Lib\Hub;

use App\Models\Setting;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The address the Hub reaches this engine at, `https://<ip>:2011`. A literal
 * public address only: the Hub refuses anything else, and saying so here is
 * cheaper than a round trip.
 */
final class EngineAddress
{
    private const NOT_PUBLIC = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    public static function default(): ?string
    {
        try {
            $ip = trim((string) Setting::get('default_ipv4'));
        } catch (\Throwable) {
            return null;
        }

        return $ip === '' ? null : $ip;
    }

    /** Null when the Hub can be pointed at it, else why not. */
    public static function problem(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return "'{$ip}' is not an IP address. Pass the engine's public address with --ip.";
        }

        // ::ffff:10.0.0.1 is 10.0.0.1.
        $mapped = preg_match('/\A::ffff:(\d+\.\d+\.\d+\.\d+)\z/i', $ip, $m) === 1 ? $m[1] : $ip;

        if (IpUtils::checkIp($mapped, self::NOT_PUBLIC)) {
            return "{$ip} is not a public address, so PanelAlpha Connect cannot reach it. "
                .'Pass the address the Hub should use with --ip.';
        }

        return null;
    }
}
