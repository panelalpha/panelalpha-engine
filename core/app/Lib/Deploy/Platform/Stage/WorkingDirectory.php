<?php

namespace App\Lib\Deploy\Platform\Stage;

/**
 * Running a command somewhere other than the app root.
 *
 * An ordinary step gets a subshell, so the `cd` does not leak into the next
 * one. The serve command must not: a subshell would put a shell between the
 * init process and the server and undo the point of `exec`.
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
