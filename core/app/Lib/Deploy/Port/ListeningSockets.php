<?php

namespace App\Lib\Deploy\Port;

/**
 * What a container is actually listening on, read from its /proc/net/tcp via the container's PID:
 * the application image may ship neither `ss` nor a shell.
 */
final class ListeningSockets
{
    /** Start of the Linux ephemeral range, where a self-picked port is wrong the moment the process restarts. */
    public const EPHEMERAL_PORT_MIN = 32768;

    /** `sl local_address rem_address st …` — state 0A is LISTEN. */
    private const LISTEN_LINE = '/^\s*\d+:\s+([0-9A-Fa-f]+):([0-9A-Fa-f]{4})\s+\S+\s+0A\s/';

    /** IPv6 ::1 as /proc prints it. */
    private const LOOPBACK_V6 = '00000000000000000000000001000000';

    /** ::ffff:0:0/96 as /proc prints it; the last eight digits are the IPv4 address. */
    private const V4_MAPPED_PREFIX = '0000000000000000FFFF0000';

    /**
     * Ports a recipe would plausibly serve on, best first; only a tie-break, never an override.
     *
     * @var list<int>
     */
    private const WEB_PORT_PREFERENCE = [8080, 3000, 8000, 80, 5000, 8090, 4000, 9000, 5173, 4200];

    /** Ports belonging to a datastore, not the application. */
    private const INFRA_PORTS = [3306, 5432, 6379, 11211, 27017, 9200, 5672, 25, 587];

    private const MAX_PORT = 65535;

    /**
     * @return list<array{addr: string, port: int}>
     */
    public static function fromProcNet(string $contents): array
    {
        $sockets = [];
        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            if (preg_match(self::LISTEN_LINE, $line, $m) !== 1) {
                continue;
            }
            $port = (int) hexdec($m[2]);
            if ($port > 0 && $port <= self::MAX_PORT) {
                $sockets[] = ['addr' => strtoupper($m[1]), 'port' => $port];
            }
        }

        return $sockets;
    }

    /**
     * Bound to loopback only, all of 127.0.0.0/8 (Docker's DNS is 127.0.0.11) or ::ffff:127.x as a JVM binds it.
     * IPv4 is little-endian in /proc, so the first octet is last.
     */
    public static function isLoopback(string $hexAddr): bool
    {
        $hex = strtoupper($hexAddr);
        if (strlen($hex) === 32) {
            if ($hex === self::LOOPBACK_V6) {
                return true;
            }
            if (! str_starts_with($hex, self::V4_MAPPED_PREFIX)) {
                return false;
            }
            $hex = substr($hex, 24);
        }

        return strlen($hex) === 8 && str_ends_with($hex, '7F');
    }

    /**
     * The port a generated deploy should forward to, null when $expected is already served.
     * A port the image declares (EXPOSE) outranks the generic preference.
     *
     * @param  list<array{addr: string, port: int}>  $sockets
     * @param  list<int>  $declared
     */
    public static function chooseAppPort(array $sockets, int $expected, array $declared = []): ?int
    {
        return self::rankedAppPorts($sockets, $expected, $declared)[0] ?? null;
    }

    /**
     * Every port {@see chooseAppPort()} could answer, best first, so a caller can try the next.
     * A port that cannot serve HTTP (sshd on 22) is never one; empty means "not yet".
     *
     * @param  list<array{addr: string, port: int}>  $sockets
     * @param  list<int>  $declared
     * @return list<int>
     */
    public static function rankedAppPorts(array $sockets, int $expected, array $declared = []): array
    {
        $reachable = self::reachablePorts($sockets);
        if ($reachable === [] || in_array($expected, $reachable, true)) {
            return [];
        }

        $reachable = array_values(array_filter($reachable, InternalPorts::isWebCandidate(...)));
        if ($reachable === []) {
            return [];
        }

        $ranked = [];
        foreach ([...$declared, ...self::WEB_PORT_PREFERENCE] as $preferred) {
            if (in_array($preferred, $reachable, true) && ! in_array($preferred, $ranked, true)) {
                $ranked[] = $preferred;
            }
        }
        $rest = array_values(array_diff($reachable, $ranked));
        sort($rest);

        return [...$ranked, ...$rest];
    }

    /**
     * Whether the container already answers on the port we publish, which chooseAppPort()'s null cannot tell.
     *
     * @param  list<array{addr: string, port: int}>  $sockets
     */
    public static function serves(array $sockets, int $port): bool
    {
        foreach ($sockets as $socket) {
            if ((int) $socket['port'] === $port && ! self::isLoopback((string) $socket['addr'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{addr: string, port: int}>  $sockets
     * @return list<int>
     */
    private static function reachablePorts(array $sockets): array
    {
        $ports = [];
        foreach ($sockets as $socket) {
            $port = (int) $socket['port'];
            if (self::isReachable((string) $socket['addr'], $port)) {
                $ports[$port] = true;
            }
        }

        return array_keys($ports);
    }

    private static function isReachable(string $addr, int $port): bool
    {
        return ! self::isLoopback($addr)
            && ! in_array($port, self::INFRA_PORTS, true)
            && $port < self::EPHEMERAL_PORT_MIN;
    }
}
