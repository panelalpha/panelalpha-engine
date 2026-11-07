<?php

namespace App\Lib\Helpers;

/**
 * Parse --proxy-to values used by domain CLI.
 *
 * Accepts full host:port, or a bare port (shorthand for {project}:{port}).
 */
class UpstreamSpec
{
    /**
     * @return array{0: string, 1: int} [host, port]
     */
    public static function parse(string $proxyTo, string $defaultHost): array
    {
        $proxyTo = trim($proxyTo);
        if ($proxyTo === '') {
            throw new \InvalidArgumentException('--proxy-to is required (port or host:port).');
        }

        if (preg_match('/^\d+$/', $proxyTo) === 1) {
            return self::portOnly($proxyTo, $defaultHost);
        }

        if (preg_match('/^([^:\s]+):(\d+)$/', $proxyTo, $m) !== 1) {
            throw new \InvalidArgumentException(
                "Invalid --proxy-to '{$proxyTo}'. Expected port (e.g. 8080) or host:port (e.g. myproject:8080)."
            );
        }

        $port = (int) $m[2];
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Upstream port must be 1-65535.');
        }

        return [$m[1], $port];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function portOnly(string $proxyTo, string $defaultHost): array
    {
        $port = (int) $proxyTo;
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Upstream port must be 1-65535.');
        }
        $host = trim($defaultHost);
        if ($host === '') {
            throw new \InvalidArgumentException('Default upstream host is required for port-only --proxy-to.');
        }

        return [$host, $port];
    }
}
