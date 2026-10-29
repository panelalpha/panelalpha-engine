<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

/**
 * The address the caller gave for the source site (`source_ip`). It covers the
 * source host and its `www.` twin, so a redirect between the two stays on it.
 */
final class SourceIpPin
{
    public readonly string $host;

    public function __construct(string $host, public readonly string $ip)
    {
        $this->host = strtolower(trim($host, '[]'));
    }

    public function covers(string $host): bool
    {
        return self::sameSite($host, $this->host);
    }

    /**
     * Two hosts that differ at most by a leading `www.`.
     */
    public static function sameSite(string $a, string $b): bool
    {
        return self::bareHost($a) === self::bareHost($b);
    }

    private static function bareHost(string $host): string
    {
        $host = strtolower(trim($host, '[]'));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
