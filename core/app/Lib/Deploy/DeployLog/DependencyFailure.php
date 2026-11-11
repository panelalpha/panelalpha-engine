<?php

namespace App\Lib\Deploy\DeployLog;

/**
 * Names the containers `docker compose up` gave up on as dependencies and picks the line of their output that
 * says why, which compose itself never reports. No Laravel dependencies.
 */
final class DependencyFailure
{
    /** Marks the lines this class writes; the explainer rule keys on it. */
    public const PREFIX = 'error: service ';

    /** How much of a container's own output to quote. */
    private const CAUSE_LINES = 6;

    /** How far back in a container's output to look. */
    private const WINDOW = 40;

    /**
     * A line that says what went wrong, in the forms datastores and runtimes
     * print it: `Error: …`, `FATAL:  …`, `[ERROR] [MY-010119] …`, `panic: …`.
     */
    private const CAUSE = '/(?:^|\s|\[)(?:error|fatal|panic|exception|traceback|critical|emerg)\b[:\]\s]/i';

    /**
     * The containers or services compose gave up on, with what it said about
     * them, in the order it said it.
     *
     * @return list<array{kind: 'container'|'service', name: string, state: string}>
     */
    public static function failed(string $composeOutput): array
    {
        $found = [];
        if (preg_match_all('/dependency failed to start: container (\S+) (is unhealthy|exited \(\d+\))/', $composeOutput, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $match) {
                $found[$match[1]] = ['kind' => 'container', 'name' => $match[1], 'state' => self::state($match[2])];
            }
        }
        if (preg_match_all('/service "([^"]+)" didn.?t complete successfully:? (exit(?: code)? \d+)/', $composeOutput, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $match) {
                $found[$match[1]] ??= ['kind' => 'service', 'name' => $match[1], 'state' => self::state($match[2])];
            }
        }

        return array_values($found);
    }

    /** `is unhealthy` -> `unhealthy`, `exited (1)` / `exit code 1` -> `exited with code 1`. */
    private static function state(string $said): string
    {
        return preg_match('/(\d+)/', $said, $m) === 1 ? "exited with code {$m[1]}" : 'unhealthy';
    }

    /**
     * The part of a container's output that says why: from the first error line in its last WINDOW lines, else
     * the last few lines, without compose's `name  | ` prefix and docker's timestamps.
     */
    public static function cause(string $logs): string
    {
        $lines = [];
        foreach (preg_split('/\R/', $logs) ?: [] as $line) {
            $line = (string) preg_replace('/^[\w.-]+\s+\|\s?/', '', $line);
            $line = (string) preg_replace('/^\d{4}-\d{2}-\d{2}T[\d:.]+Z\s/', '', $line);
            if (trim($line) !== '') {
                $lines[] = rtrim($line);
            }
        }
        $window = array_slice($lines, -self::WINDOW);
        foreach ($window as $i => $line) {
            if (preg_match(self::CAUSE, $line) === 1) {
                return implode("\n", array_slice($window, $i, self::CAUSE_LINES));
            }
        }

        return implode("\n", array_slice($window, -self::CAUSE_LINES));
    }

    /**
     * What the newest failing health probe printed, from a container's `State.Health`, which often gives the
     * reason the service's own output lacks; empty when no probe failed.
     *
     * @param  array<string, mixed>|null  $health
     */
    public static function probe(?array $health): string
    {
        $log = is_array($health['Log'] ?? null) ? $health['Log'] : [];
        foreach (array_reverse($log) as $entry) {
            $output = is_array($entry) ? trim((string) ($entry['Output'] ?? '')) : '';
            if (($entry['ExitCode'] ?? 0) !== 0 && $output !== '') {
                return implode("\n", array_slice(preg_split('/\R/', $output) ?: [], 0, self::CAUSE_LINES));
            }
        }

        return '';
    }

    /**
     * The line leading the failure output for one failed dependency: its name and state, what its health
     * check said, then what it printed, folded onto one line.
     */
    public static function describe(string $name, string $state, string $cause, string $probe = ''): string
    {
        $cause = self::oneLine($cause);
        $probe = str_replace(']', ')', self::oneLine($probe));
        $head = self::PREFIX."{$name} did not start ({$state})";
        if ($probe !== '') {
            $head .= " [health check: {$probe}]";
        }

        return $cause === '' ? $head : $head.': '.$cause;
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
