<?php

namespace App\Lib\Deploy\Env;

/**
 * An env file's values changed in place. Only a line whose value changes is
 * rewritten, and on it only the value: every other byte of the file, and the
 * line's `export`, spacing, inline comment and line ending, are kept. A value
 * that spans lines is left as it is, and so are the lines it spans.
 */
final class EnvFileValues
{
    private const ASSIGNMENT = '/^([ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=[ \t]*)(.*?)(\r?\n)?$/sD';

    private const SINGLE = "'";

    private const DOUBLE = '"';

    private const BARE = '';

    private const DOUBLE_QUOTED_ESCAPES = ['\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\"' => '"', '\\\\' => '\\'];

    /**
     * @param  \Closure(string, string): string  $replace  key and value to the value it should have
     */
    public static function replaced(string $contents, \Closure $replace): string
    {
        $lines = preg_split('/(?<=\n)/', $contents, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $openQuote = null;
        foreach ($lines as $index => $line) {
            if ($openQuote !== null) {
                $openQuote = self::closingQuoteAt($line, 0, $openQuote) === null ? $openQuote : null;

                continue;
            }
            [$lines[$index], $openQuote] = self::replacedLine($line, $replace);
        }

        return implode('', $lines);
    }

    /**
     * @param  \Closure(string, string): string  $replace
     * @return array{0: string, 1: ?string} the line, and the quote a value it opens leaves open
     */
    private static function replacedLine(string $line, \Closure $replace): array
    {
        if (preg_match(self::ASSIGNMENT, $line, $m) !== 1) {
            return [$line, null];
        }
        [, $head, $key, $rest] = $m;
        $quote = in_array($rest[0] ?? '', [self::SINGLE, self::DOUBLE], true) ? $rest[0] : self::BARE;
        $end = self::valueEnd($rest, $quote);
        if ($end === null) {
            return [$line, $quote];
        }
        $value = self::valueOf(substr($rest, 0, $end), $quote);
        $replacement = $replace($key, $value);
        if ($replacement === $value) {
            return [$line, null];
        }

        return [$head.self::written($replacement, $quote).substr($rest, $end).($m[4] ?? ''), null];
    }

    /** Where the value's text ends in $rest, or null when its quote is not closed on this line. */
    private static function valueEnd(string $rest, string $quote): ?int
    {
        if ($quote !== self::BARE) {
            $close = self::closingQuoteAt($rest, 1, $quote);

            return $close === null ? null : $close + 1;
        }
        $comment = preg_match('/[ \t]#/', $rest, $found, PREG_OFFSET_CAPTURE) === 1 ? $found[0][1] : strlen($rest);

        return strlen(rtrim(substr($rest, 0, $comment)));
    }

    private static function closingQuoteAt(string $text, int $from, string $quote): ?int
    {
        for ($i = $from, $length = strlen($text); $i < $length; $i++) {
            if ($quote === self::DOUBLE && $text[$i] === '\\') {
                $i++;
            } elseif ($text[$i] === $quote) {
                return $i;
            }
        }

        return null;
    }

    private static function valueOf(string $token, string $quote): string
    {
        return match ($quote) {
            self::SINGLE => substr($token, 1, -1),
            self::DOUBLE => strtr(substr($token, 1, -1), self::DOUBLE_QUOTED_ESCAPES),
            default => $token,
        };
    }

    /** $value in the quoting the line had, so a `$` in it means what it meant there. */
    private static function written(string $value, string $quote): string
    {
        if ($quote === self::SINGLE && preg_match("/['\r\n]/", $value) !== 1) {
            return self::SINGLE.$value.self::SINGLE;
        }
        if ($quote === self::BARE && preg_match('/^[^\s"\'#\\\\]*$/D', $value) === 1) {
            return $value;
        }

        $literalDollar = $quote === self::SINGLE ? ['$' => '$$'] : [];

        return self::DOUBLE.strtr($value, array_flip(self::DOUBLE_QUOTED_ESCAPES) + $literalDollar).self::DOUBLE;
    }
}
