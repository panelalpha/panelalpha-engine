<?php

namespace App\Lib\Deploy\Platform\Stage;

/**
 * Single-quoting for the entrypoint, where every value the engine
 * interpolates came from a manifest or a project and none of it is trusted
 * to be free of spaces or quotes.
 */
final class ShellQuote
{
    public static function of(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
