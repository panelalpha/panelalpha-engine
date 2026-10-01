<?php

namespace App\Lib\Deploy\Port;

/**
 * What a container is actually listening on, read from its /proc/net/tcp via
 * the container's PID: the application image may ship neither `ss` nor a
 * shell.
 */
final class ListeningSockets
{
    /**
     * Start of the Linux ephemeral range
     * (/proc/sys/net/ipv4/ip_local_port_range). A runtime picking a free port
     * for itself listens here (Rails opens one while booting), and such a
     * mapping is wrong the moment the process restarts.
     */
    public const EPHEMERAL_PORT_MIN = 32768;

    /** `sl local_address rem_address st …` — state 0A is LISTEN. */
    private const LISTEN_LINE = '/^\s*\d+:\s+([0-9A-Fa-f]+):([0-9A-Fa-f]{4})\s+\S+\s+0A\s/';

    /** IPv6 ::1 as /proc prints it. */
    private const LOOPBACK_V6 = '00000000000000000000000001000000';

    /** ::ffff:0:0/96 as /proc prints it; the last eight digits are the IPv4 address. */
    private const V4_MAPPED_PREFIX = '0000000000000000FFFF0000';

    /**
     * Ports a recipe would plausibly serve on, best first. Used only to break
     * ties when a container listens on several — never to override something
     * that is already answering.
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
     * Bound to loopback only: nothing outside the container can reach it, so
     * remapping a published port would not help.
     */
    public static function isLoopback(string $hexAddr): bool
    {
        $hex = strtoupper($hexAddr);
        if (strlen($hex) === 32) {
            if ($hex === self::LOOPBACK_V6) {
                return true;
            }
            // A JVM binds 127.0.0.1 as ::ffff:127.0.0.1 in tcp6.
            if (!str_starts_with($hex, self::V4_MAPPED_PREFIX)) {
                return false;
            }
            $hex = substr($hex, 24);
        }

        // All of 127.0.0.0/8, not just .1: Docker's embedded DNS listens on
        // 127.0.0.11. IPv4 is little-endian in /proc, so the first octet is last.
        return strlen($hex) === 8 && str_ends_with($hex, '7F');
    }

    /**
     * The port a generated deploy should actually forward to. Null when
     * $expected is already served, otherwise the port the application really
     * bound: a recipe's guess, or an app that ignores $PORT (PocketBase),
     * would otherwise be a green deploy behind a 502.
     *
     * A port the image itself declares ($declared, its Dockerfile's EXPOSE)
     * outranks the generic preference: rapidbay listens on 80 (a stock nginx
     * page) and on its declared 5000.
     *
     * @param list<array{addr: string, port: int}> $sockets
     * @param list<int> $declared
     */
    public static function chooseAppPort(array $sockets, int $expected, array $declared = []): ?int
    {
        $reachable = self::reachablePorts($sockets);
        if ($reachable === [] || in_array($expected, $reachable, true)) {
            return null;
        }

        // A port that cannot serve HTTP is not an answer to "where is the
        // site": Gitea's sshd binds 22 before the app binds 3000, and the API
        // read 3000 while traffic went to sshd. Empty means "not yet".
        $reachable = array_values(array_filter($reachable, InternalPorts::isWebCandidate(...)));
        if ($reachable === []) {
            return null;
        }

        foreach ([...$declared, ...self::WEB_PORT_PREFERENCE] as $preferred) {
            if (in_array($preferred, $reachable, true)) {
                return $preferred;
            }
        }
        sort($reachable);

        return $reachable[0];
    }

    /**
     * Whether the container already answers on the port we publish.
     * chooseAppPort() returns null both for "all good" and for "nothing worth
     * pointing at yet"; the caller must tell those apart before it stops
     * waiting.
     *
     * @param list<array{addr: string, port: int}> $sockets
     */
    public static function serves(array $sockets, int $port): bool
    {
        foreach ($sockets as $socket) {
            if ((int) $socket['port'] === $port && !self::isLoopback((string) $socket['addr'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{addr: string, port: int}> $sockets
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
        return !self::isLoopback($addr)
            && !in_array($port, self::INFRA_PORTS, true)
            && $port < self::EPHEMERAL_PORT_MIN;
    }
}
