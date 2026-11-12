<?php

namespace App\Lib\Deploy\Source;

use App\System\Privileged\AccountFiles;

/**
 * Locates the project root in an extracted archive: its only subdirectory when it holds nothing else, else itself.
 * Listed as the account via {@see topLevelArgv()}, since core cannot read ~/.panelalpha. No Laravel dependencies.
 */
class ProjectArchive
{
    /**
     * The top level of $dir as `<type> <name>` records, NUL-terminated, where
     * type is find's `%y` letter (`d` directory, `f` file, `l` link, ...).
     *
     * @return list<string>
     */
    public static function topLevelArgv(AccountFiles $account, string $dir): array
    {
        return $account->listEntryTypes($dir);
    }

    /**
     * @param  string  $listing  the output of {@see topLevelArgv()} for $dir
     */
    public static function findSingleDir(string $dir, string $listing): ?string
    {
        $found = null;
        foreach (explode("\0", $listing) as $record) {
            if ($record === '') {
                continue;
            }
            [$type, $name] = array_pad(explode(' ', $record, 2), 2, '');
            if ($name === '' || $name === '.DS_Store') {
                continue;
            }
            if ($type !== 'd' || $found !== null) {
                return null;
            }
            $found = rtrim($dir, '/').'/'.$name;
        }

        return $found;
    }

    public static function resolveProjectRoot(string $dir, string $listing): string
    {
        return self::findSingleDir($dir, $listing) ?? $dir;
    }
}
