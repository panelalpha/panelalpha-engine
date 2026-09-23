<?php

namespace App\Lib\Deploy;

use App\Lib\Deploy\Port\ComposePortScan;
use App\Lib\Deploy\Port\ListeningSockets;

/**
 * Which port the engine should publish for an application.
 *
 * Two independent answers, and the pipeline uses both. Before the container
 * exists, {@see ComposePortScan} reads what the compose file offers and
 * filters out everything that belongs to a datastore. Once it is running,
 * {@see ListeningSockets} reads what the process actually bound — which is
 * the only way to catch an application that ignores $PORT and would otherwise
 * pass a green deploy behind a 502.
 *
 * No Laravel dependencies — unit-testable.
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
     * @param list<array{addr: string, port: int}> $sockets
     */
    public static function chooseAppPort(array $sockets, int $expected): ?int
    {
        return ListeningSockets::chooseAppPort($sockets, $expected);
    }

    /**
     * @param list<array{addr: string, port: int}> $sockets
     */
    public static function servesPort(array $sockets, int $port): bool
    {
        return ListeningSockets::serves($sockets, $port);
    }

    /**
     * Public ports, best first, plus the bindings detection refused and why
     * ({@see ComposePortScan}).
     *
     * @return array{all: list<int>, primary?: int, refused: list<array{port: int, reason: string, service: string}>}
     */
    public static function detectAllPorts(string $composePath): array
    {
        return ComposePortScan::of($composePath);
    }

    public static function detectPrimaryPort(string $composePath): int
    {
        return ComposePortScan::primaryOf($composePath);
    }
}
