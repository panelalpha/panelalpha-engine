<?php

namespace App\Lib\Deploy\Compose;

/**
 * One container as `docker compose ps --format json` prints it, from the daemon's list row and the container's inspect:
 * each field read where compose reads it, cut and joined as its default, truncating, output does.
 */
final class ComposePsRow
{
    private const PROJECT_LABEL = 'com.docker.compose.project';

    private const SERVICE_LABEL = 'com.docker.compose.service';

    private const COMMAND_WIDTH = 20;

    private const MOUNT_WIDTH = 15;

    /** How Go's strconv.Quote writes the ASCII characters it escapes by name. */
    private const ESCAPES = [
        '"' => '\\"', '\\' => '\\\\', "\x07" => '\\a', "\x08" => '\\b', "\f" => '\\f', "\n" => '\\n', "\r" => '\\r',
        "\t" => '\\t', "\v" => '\\v',
    ];

    /**
     * Compose reads the exit code of an exited container and the health of a running one from its inspect, the rest
     * from the list.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $inspect
     * @return array<string, mixed>
     */
    public static function of(array $row, array $inspect, int $now): array
    {
        $state = is_array($inspect['State'] ?? null) ? $inspect['State'] : [];
        $status = (string) ($state['Status'] ?? $row['State'] ?? '');
        $labels = is_array($row['Labels'] ?? null) ? $row['Labels'] : [];
        $mounts = is_array($row['Mounts'] ?? null) ? $row['Mounts'] : [];
        $publishers = self::publishers($inspect['NetworkSettings']['Ports'] ?? null);
        $created = (int) ($row['Created'] ?? 0);

        return [
            'Command' => self::quoted(self::ellipsis((string) ($row['Command'] ?? ''), self::COMMAND_WIDTH)),
            'CreatedAt' => gmdate('Y-m-d H:i:s', $created).' +0000 UTC',
            'Engine' => '',
            'ExitCode' => in_array($status, ['exited', 'dead'], true) ? (int) ($state['ExitCode'] ?? 0) : 0,
            'Health' => $status === 'running' ? (string) ($state['Health']['Status'] ?? '') : '',
            'ID' => substr((string) $row['Id'], 0, 12),
            'Image' => (string) ($row['Image'] ?? ''),
            'Labels' => implode(',', array_map(static fn (int|string $key, mixed $value): string => $key.'='.$value, array_keys($labels), $labels)),
            'LocalVolumes' => (string) count(array_filter($mounts, static fn (mixed $mount): bool => ($mount['Driver'] ?? '') === 'local')),
            'Mounts' => implode(',', array_map(self::mountName(...), $mounts)),
            'Name' => self::nameOf($row),
            'Names' => implode(',', array_map(static fn (mixed $name): string => ltrim((string) $name, '/'), (array) ($row['Names'] ?? []))),
            'Networks' => implode(',', array_keys((array) ($row['NetworkSettings']['Networks'] ?? []))),
            'Ports' => self::ports($publishers),
            'Project' => (string) ($labels[self::PROJECT_LABEL] ?? ''),
            'Publishers' => $publishers,
            'RunningFor' => self::humanDuration($now - $created).' ago',
            'Service' => (string) ($labels[self::SERVICE_LABEL] ?? ''),
            'Size' => '0B',
            'State' => (string) ($row['State'] ?? ''),
            'Status' => (string) ($row['Status'] ?? ''),
        ];
    }

    /** @param  array<string, mixed>  $row */
    public static function nameOf(array $row): string
    {
        return ltrim((string) ($row['Names'][0] ?? ''), '/');
    }

    /**
     * One publisher a binding of each port, and one with no published port for a port bound to nothing, by target port
     * as compose sorts them.
     *
     * @return list<array{URL: string, TargetPort: int, PublishedPort: int, Protocol: string}>
     */
    private static function publishers(mixed $ports): array
    {
        $publishers = [];
        foreach (is_array($ports) ? $ports : [] as $port => $bindings) {
            [$target, $protocol] = array_pad(explode('/', (string) $port, 2), 2, 'tcp');
            foreach (is_array($bindings) && $bindings !== [] ? $bindings : [null] as $binding) {
                $publishers[] = [
                    'URL' => (string) ($binding['HostIp'] ?? ''),
                    'TargetPort' => (int) $target,
                    'PublishedPort' => (int) ($binding['HostPort'] ?? 0),
                    'Protocol' => $protocol,
                ];
            }
        }
        usort($publishers, static fn (array $a, array $b): int => [$a['TargetPort'], $a['URL'], $a['PublishedPort'], $a['Protocol']]
            <=> [$b['TargetPort'], $b['URL'], $b['PublishedPort'], $b['Protocol']]);

        return $publishers;
    }

    /**
     * The CLI's port column: consecutive ports bound to themselves on one address and protocol as one range, in the
     * order each range began, then every port published under another number.
     *
     * @param  list<array{URL: string, TargetPort: int, PublishedPort: int, Protocol: string}>  $publishers  sorted as {@see publishers()} sorts them
     */
    private static function ports(array $publishers): string
    {
        $ended = [];
        $open = [];
        $remapped = [];
        foreach ($publishers as $publisher) {
            if ($publisher['URL'] !== '' && $publisher['PublishedPort'] !== $publisher['TargetPort']) {
                $remapped[] = self::hostPort($publisher['URL'], (string) $publisher['PublishedPort']).'->'.$publisher['TargetPort'].'/'.$publisher['Protocol'];

                continue;
            }
            $key = $publisher['URL'].'/'.$publisher['Protocol'];
            $range = $open[$key] ?? null;
            if ($range !== null && $publisher['TargetPort'] === $range['last'] + 1) {
                $open[$key]['last'] = $publisher['TargetPort'];

                continue;
            }
            if ($range !== null) {
                $ended[] = self::portRange($range);
            }
            $open[$key] = ['address' => $publisher['URL'], 'protocol' => $publisher['Protocol'], 'first' => $publisher['TargetPort'], 'last' => $publisher['TargetPort']];
        }

        return implode(', ', [...$ended, ...array_map(self::portRange(...), array_values($open)), ...$remapped]);
    }

    /** @param  array{address: string, protocol: string, first: int, last: int}  $range */
    private static function portRange(array $range): string
    {
        $ports = $range['first'] === $range['last'] ? (string) $range['first'] : $range['first'].'-'.$range['last'];
        $published = $range['address'] === '' ? $ports : self::hostPort($range['address'], $ports).'->'.$ports;

        return $published.'/'.$range['protocol'];
    }

    /** As Go's net.JoinHostPort writes an address and port, an IPv6 address in brackets. */
    private static function hostPort(string $address, string $port): string
    {
        return (str_contains($address, ':') ? '['.$address.']' : $address).':'.$port;
    }

    private static function mountName(mixed $mount): string
    {
        $name = (string) ($mount['Name'] ?? '');

        return self::ellipsis($name !== '' ? $name : (string) ($mount['Source'] ?? ''), self::MOUNT_WIDTH);
    }

    /**
     * At most $width wide, cut to one less and ended with an ellipsis when longer, as the CLI's Ellipsis does: ASCII by
     * its length, other text by its display width.
     */
    private static function ellipsis(string $text, int $width): string
    {
        if (strlen($text) !== mb_strlen($text, 'UTF-8')) {
            return mb_strimwidth($text, 0, $width, '…', 'UTF-8');
        }

        return strlen($text) <= $width ? $text : substr($text, 0, $width - 1).'…';
    }

    /** In double quotes, as Go's strconv.Quote writes text whose only characters to escape are ASCII. */
    private static function quoted(string $text): string
    {
        $escaped = (string) preg_replace_callback(
            '/[\x00-\x1f\x7f"\\\\]/',
            static fn (array $match): string => self::ESCAPES[$match[0]] ?? sprintf('\\x%02x', ord($match[0])),
            $text,
        );

        return '"'.$escaped.'"';
    }

    /** How long ago, in the words of the Docker CLI's `RunningFor` and a running container's status. */
    private static function humanDuration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $hours = (int) floor($seconds / 3600 + 0.5);

        return match (true) {
            $seconds < 1 => 'Less than a second',
            $seconds === 1 => '1 second',
            $seconds < 60 => $seconds.' seconds',
            $minutes === 1 => 'About a minute',
            $minutes < 60 => $minutes.' minutes',
            $hours === 1 => 'About an hour',
            $hours < 48 => $hours.' hours',
            $hours < 24 * 7 * 2 => intdiv($hours, 24).' days',
            $hours < 24 * 30 * 2 => intdiv($hours, 24 * 7).' weeks',
            $hours < 24 * 365 * 2 => intdiv($hours, 24 * 30).' months',
            default => intdiv(intdiv($seconds, 3600), 24 * 365).' years',
        };
    }
}
