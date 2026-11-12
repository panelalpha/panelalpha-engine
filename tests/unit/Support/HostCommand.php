<?php

namespace Tests\Support;

/** The command the broker runs on the host for a gateway request. */
final class HostCommand
{
    /**
     * @param  string|list<string>  $command
     * @return string|list<string>
     */
    public static function argv(string|array $command): string|array
    {
        return is_array($command) ? LocalRoot::plain($command) : $command;
    }

    /**
     * {@see argv()}, or `pa-root <area.action>` for an action the broker answers itself, which runs no command a fake
     * host could answer by its argv.
     *
     * @param  string|list<string>  $command
     * @return string|list<string>
     */
    public static function asked(string|array $command): string|array
    {
        $argv = self::argv($command);

        return $argv === [] && is_array($command) && LocalRoot::isRequest($command)
            ? ['pa-root', (string) LocalRoot::action($command)]
            : $argv;
    }
}
