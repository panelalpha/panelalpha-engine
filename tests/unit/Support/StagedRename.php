<?php

namespace Tests\Support;

/**
 * The last step of Filesystem::writeFileReplacingPath(), for a double that
 * captures writes: `sudo mv -T STAGED TARGET` outside the homes, and the
 * account-home helper's `rename ROOT STAGED TARGET` inside one.
 */
final class StagedRename
{
    /**
     * @param  string|list<string>  $cmd
     * @return array{0: string, 1: string}|null staged path and target
     */
    public static function of(string|array $cmd): ?array
    {
        $argv = is_array($cmd) ? array_values($cmd) : (preg_split('/\s+/', trim($cmd)) ?: []);
        if (count($argv) === 5 && array_slice($argv, 0, 3) === ['sudo', 'mv', '-T']) {
            return [$argv[3], $argv[4]];
        }
        if (count($argv) === 8 && array_slice($argv, 0, 3) === ['sudo', 'python3', '-I'] && $argv[4] === 'rename') {
            return [$argv[6], $argv[7]];
        }

        return null;
    }
}
