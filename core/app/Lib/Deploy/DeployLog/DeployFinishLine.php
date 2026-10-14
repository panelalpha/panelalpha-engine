<?php

namespace App\Lib\Deploy\DeployLog;

/**
 * The line finish() ends a deploy log with, and the verdict read back from it.
 */
final class DeployFinishLine
{
    /** How many lines from the end of a log the finish line is looked for in. */
    public const LOOKBACK = 60;

    /** How a finished deploy announces itself, by status. */
    private const MESSAGES = [
        DeployLogger::STATUS_SUCCESS => 'Deploy finished successfully',
        DeployLogger::STATUS_PARTIAL => 'Deploy finished with warnings',
        DeployLogger::STATUS_CANCELLED => 'Deploy cancelled',
        DeployLogger::STATUS_FAILED => 'Deploy failed',
    ];

    public static function message(string $status, ?string $error): string
    {
        $message = self::MESSAGES[$status] ?? self::MESSAGES[DeployLogger::STATUS_FAILED];

        return $error === null || $error === '' ? $message : $message . ': ' . $error;
    }

    /**
     * The last finish line among these lines, oldest first.
     *
     * @param list<array{ts: int, level: string, msg: string}> $lines
     * @return array{status: string, error: ?string, ts: int}|null
     */
    public static function find(array $lines): ?array
    {
        foreach (array_reverse($lines) as $line) {
            if (!in_array($line['level'], [DeployLogger::LEVEL_OK, DeployLogger::LEVEL_ERROR], true)) {
                continue;
            }
            $verdict = self::verdict($line['msg']);
            if ($verdict !== null) {
                return $verdict + ['ts' => $line['ts']];
            }
        }

        return null;
    }

    /**
     * @return array{status: string, error: ?string}|null
     */
    private static function verdict(string $msg): ?array
    {
        foreach (self::MESSAGES as $status => $message) {
            if ($msg === $message) {
                return ['status' => $status, 'error' => null];
            }
            if (str_starts_with($msg, $message . ': ')) {
                return ['status' => $status, 'error' => substr($msg, strlen($message) + 2)];
            }
        }

        return null;
    }
}
