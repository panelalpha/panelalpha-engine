<?php

namespace App\Lib\Deploy\DeployLog;

/**
 * Turning a line of subprocess output into something safe to show a customer.
 *
 * Applications print their connection strings verbatim when a connection
 * fails, including the ones the engine generated.
 */
final class LogLine
{
    public const MAX_LENGTH = 4000;

    /** ANSI colours, cursor moves and OSC sequences. */
    private const ANSI = '/\x1B(?:\[[0-9;?]*[A-Za-z]|\][^\x07]*\x07)/';

    /** Credentials in a URL of any scheme, not just http(s). */
    private const URL_CREDENTIALS = '#([a-z][a-z0-9+.-]*://)[^\s/:@]*(:[^\s/@]*)?@#i';

    private const BEARER = '/(Authorization:\s*Bearer)\s+\S+/i';

    public static function sanitize(string $line): string
    {
        $line = (string) preg_replace(self::ANSI, '', $line);
        $line = (string) preg_replace(self::URL_CREDENTIALS, '$1***@', $line);
        $line = (string) preg_replace(self::BEARER, '$1 ***', $line);

        return trim($line);
    }

    public static function truncate(string $message): string
    {
        return strlen($message) > self::MAX_LENGTH
            ? substr($message, 0, self::MAX_LENGTH).'…'
            : $message;
    }

    /**
     * docker progress rewrites the same line with \r — keep the final state.
     */
    public static function lastOverwrite(string $line): string
    {
        $carriage = strrpos($line, "\r");

        return $carriage === false ? $line : substr($line, $carriage + 1);
    }
}
