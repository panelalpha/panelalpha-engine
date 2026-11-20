<?php

namespace Tests\Integration\System\Project;

use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;

/**
 * A finished, successful process for stubbed Systems: one shared device per path,
 * so a home write finds its stage on the same filesystem, and every path resolved.
 */
final class SuccessfulProcess
{
    /** @param string|list<string> $cmd */
    public static function for(string|array $cmd): Process
    {
        $argv = (array) $cmd;
        $values = LocalRoot::values($argv);
        $output = match (LocalRoot::action($argv)) {
            'files.deviceNumbers' => str_repeat("1\n", count($values)),
            'files.existingRealPaths' => implode("\n", $values)."\n",
            default => '',
        };
        $process = new Process(['php', '-r', 'echo $argv[1];', $output]);
        $process->run();

        return $process;
    }
}
