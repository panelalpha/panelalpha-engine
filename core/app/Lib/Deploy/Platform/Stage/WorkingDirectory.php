<?php

namespace App\Lib\Deploy\Platform\Stage;

/**
 * Running a command somewhere other than the app root: a step gets a subshell so the `cd` does not
 * leak, but the serve command must not, or a shell would sit between init and the server.
 */
final class WorkingDirectory
{
    public static function wrap(string $run, ?string $workdir): string
    {
        return self::isRoot($workdir) ? $run : '( cd '.$workdir.' && '.$run.' )';
    }

    public static function wrapForExec(string $run, ?string $workdir): string
    {
        return self::isRoot($workdir)
            ? $run
            : 'sh -c '.ShellQuote::of('cd '.$workdir.' && exec '.$run);
    }

    private static function isRoot(?string $workdir): bool
    {
        return $workdir === null || $workdir === '' || $workdir === '.';
    }
}
