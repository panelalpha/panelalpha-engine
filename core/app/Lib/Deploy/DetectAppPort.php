<?php

namespace App\Lib\Deploy;

use App\Lib\Deploy\Port\ComposePortScan;
use App\Lib\Deploy\Port\ListeningSockets;

/**
 * Which port the engine publishes: {@see ComposePortScan} reads what compose offers before the container exists,
 * {@see ListeningSockets} what it bound, catching an app ignoring $PORT behind a 502. No Laravel dependencies.
 */
class DetectAppPort
{
    public const EPHEMERAL_PORT_MIN = ListeningSockets::EPHEMERAL_PORT_MIN;

    /**
     * TCP sockets in LISTEN state, from /proc/net/tcp or /proc/net/tcp6.
     *
     * @return list<array{addr: string, port: int}>
     */
    public static function listeningSocketsFromProcNet(string $contents): array
    {
        return ListeningSockets::fromProcNet($contents);
    }

    public static function isLoopbackAddress(string $hexAddr): bool
    {
        return ListeningSockets::isLoopback($hexAddr);
    }

    /**
     * The port a running deploy should forward to, or null when $expected is
     * already being served.
     *
     * @param  list<array{addr: string, port: int}>  $sockets
     * @param  list<int>  $declared  ports the image declares, preferred
     */
    public static function chooseAppPort(array $sockets, int $expected, array $declared = []): ?int
    {
        return ListeningSockets::chooseAppPort($sockets, $expected, $declared);
    }

    /**
     * @param  list<array{addr: string, port: int}>  $sockets
     * @param  list<int>  $declared
     * @return list<int>
     */
    public static function rankedAppPorts(array $sockets, int $expected, array $declared = []): array
    {
        return ListeningSockets::rankedAppPorts($sockets, $expected, $declared);
    }

    /**
     * @param  list<array{addr: string, port: int}>  $sockets
     */
    public static function servesPort(array $sockets, int $port): bool
    {
        return ListeningSockets::serves($sockets, $port);
    }

    /**
     * Public ports, best first, plus the bindings detection refused and why
     * ({@see ComposePortScan}).
     *
     * @param  array<string, string>  $env  the project's `.env`, for host ports taken from it
     * @return array{all: list<int>, primary?: int, refused: list<array{port: int, reason: string, service: string}>}
     */
    public static function detectAllPorts(string $composePath, array $env = []): array
    {
        return ComposePortScan::of($composePath, $env);
    }

    public static function detectPrimaryPort(string $composePath): int
    {
        return ComposePortScan::primaryOf($composePath);
    }
}
