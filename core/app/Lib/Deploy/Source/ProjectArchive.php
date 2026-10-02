<?php

namespace App\Lib\Deploy\Source;

/**
 * Locate the project root inside an extracted archive.
 *
 * If the extract directory contains exactly one subdirectory (and no files),
 * that subdirectory is the project root — the usual "zip of a folder" case.
 * Otherwise the extract directory itself is the root (flat archive).
 *
 * The directory is listed with {@see topLevelArgv()} rather than read here:
 * archives are extracted under the account's private ~/.panelalpha, which
 * core's PHP process cannot read. No Laravel dependencies.
 */
class ProjectArchive
{
    /**
     * The top level of $dir as `<type> <name>` records, NUL-terminated, where
     * type is find's `%y` letter (`d` directory, `f` file, `l` link, ...).
     *
     * @return list<string>
     */
    public static function topLevelArgv(string $dir): array
    {
        return ['sudo', 'find', $dir, '-mindepth', '1', '-maxdepth', '1', '-printf', '%y %f\0'];
    }

    /**
     * @param string $listing the output of {@see topLevelArgv()} for $dir
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
            $found = rtrim($dir, '/') . '/' . $name;
        }

        return $found;
    }

    public static function resolveProjectRoot(string $dir, string $listing): string
    {
        return self::findSingleDir($dir, $listing) ?? $dir;
    }
}
