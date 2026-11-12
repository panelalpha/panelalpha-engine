<?php

namespace App\Lib\Deploy\DeployLog;

use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * One deploy log line on a terminal: elapsed time, then the message coloured by level in Symfony tags.
 * Shared by `project:create` and `project:deploy:log --follow`, so the two cannot drift apart.
 */
final class DeployLineFormat
{
    /** Dim is the subprocess firehose -- shown only when asked for. */
    private const COLOURS = [
        DeployLogger::LEVEL_OK => 'green',
        DeployLogger::LEVEL_INFO => 'default',
        DeployLogger::LEVEL_WARN => 'yellow',
        DeployLogger::LEVEL_ERROR => 'red',
        DeployLogger::LEVEL_DIM => 'gray',
    ];

    private const TERMINAL = [
        DeployLogger::STATUS_SUCCESS,
        DeployLogger::STATUS_PARTIAL,
        DeployLogger::STATUS_FAILED,
        DeployLogger::STATUS_CANCELLED,
    ];

    /**
     * Null when the line is not to be shown at this verbosity. A stage's opening line becomes its heading and its
     * "finished" twin is dropped, matched on text so that a log read back from disk looks the same.
     *
     * @param  array{ts?: int, level?: string, msg?: string}  $line
     */
    public static function line(array $line, int $startedAt, bool $verbose = false): ?string
    {
        $level = (string) ($line['level'] ?? DeployLogger::LEVEL_DIM);
        if ($level === DeployLogger::LEVEL_DIM && ! $verbose) {
            return null;
        }

        $message = trim((string) ($line['msg'] ?? ''));
        if ($message === '') {
            return null;
        }

        $at = (int) ($line['ts'] ?? 0);
        if (preg_match("/^Stage '.*' finished$/", $message) === 1) {
            return null;
        }
        if (preg_match('/^Starting stage: (.+)$/', $message, $matches) === 1) {
            return self::stage($matches[1], $at, $startedAt);
        }

        $colour = self::COLOURS[$level] ?? 'default';

        return sprintf(
            '  <fg=gray>%s</> <fg=%s>%s</>',
            self::elapsed($at, $startedAt),
            $colour,
            self::escape($message)
        );
    }

    /** The heading a stage change gets, so the phases stand out from the lines. */
    public static function stage(string $stage, int $at, int $startedAt): string
    {
        return sprintf(
            '  <fg=gray>%s</> <options=bold>%s</>',
            self::elapsed($at, $startedAt),
            self::escape(ucfirst(str_replace('_', ' ', $stage)))
        );
    }

    /** m:ss since the deploy started, minutes padded to three; `  -:--` with no start time or for a line before it. */
    public static function elapsed(int $at, int $startedAt): string
    {
        if ($startedAt <= 0 || $at < $startedAt) {
            return '  -:--';
        }
        $seconds = $at - $startedAt;

        return sprintf('%3d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    public static function isTerminal(?string $status): bool
    {
        return $status !== null && in_array($status, self::TERMINAL, true);
    }

    /** `<` in a build's own output would otherwise be read as a style tag. */
    private static function escape(string $message): string
    {
        return OutputFormatter::escape($message);
    }
}
