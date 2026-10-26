<?php

namespace App\Lib\Deploy\Compose;

/**
 * A service's `command:` or `entrypoint:` as one string, whichever of the two
 * Compose forms the project wrote it in.
 */
final class ComposeCommand
{
    /**
     * @param  mixed  $command
     */
    public static function asString($command): string
    {
        if (is_string($command)) {
            return $command;
        }
        if (! is_array($command)) {
            return '';
        }

        $parts = array_filter($command, static fn ($part): bool => is_string($part) || is_int($part));

        return implode(' ', array_map('strval', $parts));
    }
}
