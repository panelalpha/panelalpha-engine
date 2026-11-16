<?php

namespace Tests\Support;

/**
 * A `docker compose ps --format json` fixture answered as the account daemon answers the stack's lookups: its
 * container list and each container's inspect, a row's name standing in for its id.
 */
final class ComposePsFixture
{
    /**
     * The daemon's answer to $argv from $composePs, null when $argv is neither a container list nor an inspect.
     *
     * @param  list<string>  $argv
     */
    public static function answer(array $argv, string $composePs): ?string
    {
        return match (LocalRoot::action($argv)) {
            'account-docker.listContainers' => self::listing($composePs),
            'account-docker.containers' => self::inspect($composePs, array_slice(LocalRoot::values($argv), 1)),
            default => null,
        };
    }

    public static function listing(string $composePs): string
    {
        return (string) json_encode(array_map(static fn (array $row): array => [
            'Id' => $row['Name'],
            'Names' => ['/'.$row['Name']],
            'State' => $row['State'] ?? '',
            'Status' => $row['Status'] ?? '',
            'Labels' => ['com.docker.compose.service' => $row['Service'] ?? ''],
        ], self::rows($composePs)));
    }

    /** @param  list<string>  $ids */
    public static function inspect(string $composePs, array $ids): string
    {
        $byName = array_column(self::rows($composePs), null, 'Name');

        return (string) json_encode(array_map(
            static fn (string $id): ?array => isset($byName[$id]) ? self::inspected($byName[$id]) : null,
            $ids
        ));
    }

    /** @return list<array<string, mixed>> */
    public static function rows(string $composePs): array
    {
        return array_values(array_filter(
            array_map(static fn (string $line): mixed => json_decode($line, true), preg_split('/\R/', trim($composePs)) ?: []),
            'is_array'
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function inspected(array $row): array
    {
        $ports = [];
        foreach ((array) ($row['Publishers'] ?? []) as $publisher) {
            $ports[$publisher['TargetPort'].'/'.($publisher['Protocol'] ?? 'tcp')][] = [
                'HostIp' => $publisher['URL'] ?? '',
                'HostPort' => (string) $publisher['PublishedPort'],
            ];
        }

        return [
            'Name' => '/'.$row['Name'],
            'State' => ['Status' => $row['State'] ?? '', 'ExitCode' => $row['ExitCode'] ?? 0],
            'Config' => ['Labels' => ['com.docker.compose.service' => $row['Service'] ?? '']],
            'NetworkSettings' => ['Ports' => $ports],
        ];
    }
}
