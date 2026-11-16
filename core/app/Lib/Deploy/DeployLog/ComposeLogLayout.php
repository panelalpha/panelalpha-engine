<?php

namespace App\Lib\Deploy\DeployLog;

/**
 * Containers' log tails laid out as `docker compose logs` prints them without `-t`: a name column as wide as the
 * longest name, lines in the order the daemon logged them. No Laravel dependencies.
 */
final class ComposeLogLayout
{
    /** The time the daemon leads a line with when asked for timestamps: RFC 3339 in UTC, with fixed nanoseconds. */
    private const TIMED = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z)(?: (.*))?$/s';

    /**
     * @param  list<array{0: string, 1: string}>  $tails  each container's name and its log tail, each line led by its time
     */
    public static function of(array $tails): string
    {
        $width = max([0, ...array_map(static fn (array $tail): int => strlen($tail[0]), $tails)]) + 1;
        $lines = [];
        foreach ($tails as [$name, $tail]) {
            foreach (self::timed($tail) as [$at, $line]) {
                $lines[] = [$at, sprintf("%-{$width}s | %s\n", $name, $line)];
            }
        }
        usort($lines, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return implode('', array_column($lines, 1));
    }

    /**
     * Each line of $tail without its time and with it beside; a line not led by one was logged with the line before.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function timed(string $tail): array
    {
        $tail = rtrim($tail);
        $at = '';
        $timed = [];
        foreach ($tail === '' ? [] : (preg_split('/\R/', $tail) ?: []) as $line) {
            if (preg_match(self::TIMED, $line, $m) === 1) {
                [$at, $line] = [$m[1], $m[2] ?? ''];
            }
            $timed[] = [$at, $line];
        }

        return $timed;
    }
}
