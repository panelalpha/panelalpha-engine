<?php

namespace Tests\Support;

/**
 * The last step of Filesystem::writeFileReplacingPath() for a double that
 * captures writes: `files.rename` outside the homes, `account.renameInHome` in one.
 */
final class StagedRename
{
    /**
     * @param  string|list<string>  $cmd
     * @return array{0: string, 1: string}|null staged path and target
     */
    public static function of(string|array $cmd): ?array
    {
        if (! is_array($cmd)) {
            return null;
        }
        $values = LocalRoot::values($cmd);

        return match (LocalRoot::action($cmd)) {
            'files.rename' => count($values) === 2 ? [$values[0], $values[1]] : null,
            'account.renameInHome' => count($values) === 3 ? [$values[1], $values[2]] : null,
            default => null,
        };
    }
}
