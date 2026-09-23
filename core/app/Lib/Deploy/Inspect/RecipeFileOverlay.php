<?php

namespace App\Lib\Deploy\Inspect;

use App\Lib\Deploy\Inspect\Report\AppConfigOrigin;

/**
 * Put an app config's `files/` into an inspect clone, as the deploy does.
 *
 * The deploy copies them into ~/project before detection runs, so a recipe can
 * supply what the repository lacks -- ESMira's root package.json shim is what
 * lets its runtime resolve at all. Inspect read the bare clone instead, and
 * reported a deployable app as undeployable (engine#270).
 *
 * Only for a throwaway clone: a project's own directory already has the files,
 * and inspecting it must not write to it.
 */
final class RecipeFileOverlay
{
    /**
     * @return list<string> the paths written, relative to $dir
     */
    public static function apply(string $dir, ?string $repoUrl): array
    {
        [$appConfig] = AppConfigOrigin::find($dir, $repoUrl);
        if ($appConfig === null) {
            return [];
        }

        $root = realpath($dir);
        if ($root === false) {
            return [];
        }

        $written = [];
        foreach ($appConfig->files() as $snippet) {
            $relative = ltrim((string) $snippet['path'], '/');
            if ($relative === '' || in_array('..', explode('/', $relative), true)) {
                continue;
            }

            $target = $root . '/' . $relative;
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) {
                continue;
            }
            if (file_put_contents($target, (string) $snippet['contents']) !== false) {
                $written[] = $relative;
            }
        }

        return $written;
    }
}
