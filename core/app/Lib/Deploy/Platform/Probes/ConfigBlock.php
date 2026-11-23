<?php

namespace App\Lib\Deploy\Platform\Probes;

/**
 * An object literal in a JS config file, found by the key that opens it, without parsing the file.
 */
final class ConfigBlock
{
    /** How far a block is followed before it is taken as unclosed. */
    private const MAX_LENGTH = 8000;

    /** The `{...}` block `$key:` opens in $text, or '' when there is none. */
    public static function of(string $text, string $key): string
    {
        if (preg_match('/\b'.$key.'\s*:\s*\{/', $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }

        return self::braceBlock($text, $m[0][1] + strlen($m[0][0]) - 1);
    }

    /** The text from the `{` at $open to its matching `}`. */
    private static function braceBlock(string $text, int $open): string
    {
        $depth = 0;
        $end = min(strlen($text), $open + self::MAX_LENGTH);
        for ($i = $open; $i < $end; $i++) {
            if ($text[$i] === '{') {
                $depth++;
            } elseif ($text[$i] === '}' && --$depth === 0) {
                return substr($text, $open, $i - $open + 1);
            }
        }

        return substr($text, $open, $end - $open);
    }
}
