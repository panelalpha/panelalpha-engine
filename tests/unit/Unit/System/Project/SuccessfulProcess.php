<?php

namespace Tests\Unit\System\Project;

use Symfony\Component\Process\Process;

/**
 * A finished, successful process for stubbed Systems. `sudo stat -c %d` answers
 * one shared device per path, so a write into an account's home finds the
 * write stage on the same filesystem, as it is on a real host, and
 * `sudo realpath` answers each path as already resolved.
 */
final class SuccessfulProcess
{
    /** @param string|list<string> $cmd */
    public static function for(string|array $cmd): Process
    {
        $argv = (array) $cmd;
        $output = match (true) {
            array_slice($argv, 0, 4) === ['sudo', 'stat', '-c', '%d'] => str_repeat("1\n", count($argv) - 4),
            array_slice($argv, 0, 4) === ['sudo', 'realpath', '-e', '--'] => implode("\n", array_slice($argv, 4))."\n",
            default => '',
        };
        $process = new Process(['php', '-r', 'echo $argv[1];', $output]);
        $process->run();

        return $process;
    }
}
