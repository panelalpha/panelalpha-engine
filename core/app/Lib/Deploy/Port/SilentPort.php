<?php

namespace App\Lib\Deploy\Port;

/**
 * Why a running container does not answer on the port it publishes.
 *
 * "Connection reset by peer" on a published port means Docker accepted the
 * connection and found nothing listening behind it (engine#90: teslamate
 * 4000, pretix 8080, pleroma 4000, teampass 8080, ofbiz 8443). The container
 * is alive, so the restart-loop check has nothing to say, and the deploy only
 * reported that nothing answered. What the process is listening on, read from
 * its /proc/net/tcp, says which of the usual reasons it is.
 *
 * No Laravel dependencies — unit-testable.
 */
final class SilentPort
{
    /**
     * The running containers that publish one of $hostPorts, from
     * `docker compose ps --format json` (one array, or one object per line).
     *
     * @param list<int> $hostPorts
     * @return list<array{name: string, service: string, published: int, target: int}>
     */
    public static function publishers(string $psJson, array $hostPorts): array
    {
        $found = [];
        foreach (self::rows($psJson) as $row) {
            if (strtolower((string) ($row['State'] ?? '')) !== 'running') {
                continue;
            }
            $name = (string) ($row['Name'] ?? '');
            foreach ((array) ($row['Publishers'] ?? []) as $publisher) {
                $published = (int) ($publisher['PublishedPort'] ?? 0);
                $target = (int) ($publisher['TargetPort'] ?? 0);
                if ($name === '' || $target <= 0 || !in_array($published, $hostPorts, true)) {
                    continue;
                }
                $found[$name . ':' . $target] = [
                    'name' => $name,
                    'service' => (string) ($row['Service'] ?? $name),
                    'published' => $published,
                    'target' => $target,
                ];
            }
        }

        return array_values($found);
    }

    /**
     * One sentence for one container, from what it is listening on.
     *
     * @param list<array{addr: string, port: int}> $sockets
     */
    public static function diagnose(string $service, int $published, int $target, array $sockets): string
    {
        // A loopback listener on another port is the app's own internals or
        // Docker's embedded DNS (127.0.0.11, a random port), never the answer.
        $sockets = array_values(array_filter(
            $sockets,
            static fn (array $s): bool => (int) $s['port'] === $target
                || !ListeningSockets::isLoopback((string) $s['addr'])
        ));
        if ($sockets === []) {
            return "{$service} is running but listens on no TCP port yet: it is still starting, "
                . 'or waiting for something it needs.';
        }

        $onTarget = array_values(array_filter($sockets, static fn (array $s): bool => (int) $s['port'] === $target));
        if ($onTarget !== []) {
            foreach ($onTarget as $socket) {
                if (!ListeningSockets::isLoopback((string) $socket['addr'])) {
                    return "{$service} listens on {$target}, but did not answer HTTP there: it may expect "
                        . 'HTTPS, or another protocol.';
                }
            }

            return "{$service} listens on {$target} on 127.0.0.1 only, inside its container, so connections "
                . 'from outside it are refused. It has to listen on 0.0.0.0.';
        }

        $ports = array_values(array_unique(array_map(static fn (array $s): int => (int) $s['port'], $sockets)));
        sort($ports);
        $where = $target === $published ? "{$target}" : "{$target} (published as {$published})";

        return "{$service} listens on " . implode(', ', $ports) . ", not on {$where}.";
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(string $psJson): array
    {
        $whole = json_decode(trim($psJson), true);
        if (is_array($whole) && array_is_list($whole)) {
            return array_values(array_filter($whole, is_array(...)));
        }
        $rows = [];
        foreach (preg_split('/\R/', $psJson) ?: [] as $line) {
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }
}
