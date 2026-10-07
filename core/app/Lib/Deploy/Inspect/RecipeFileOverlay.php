<?php

namespace App\Lib\Deploy\Inspect;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Inspect\Report\AppConfigOrigin;

/**
 * Put an app config's `files/` and its replace-mode compose file into an
 * inspect clone, as the deploy's AppConfigBootstrap does.
 *
 * The deploy copies them into ~/project before detection runs, so a recipe can
 * supply what the repository lacks -- ESMira's root package.json shim is what
 * lets its runtime resolve at all. Inspect read the bare clone instead, and
 * reported a deployable app as undeployable. The compose file
 * is what detection reads ahead of the repository's own: without it inspect
 * called github.com/WordPress/WordPress `php` while the deploy ran compose.
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

        $writes = $appConfig->files();
        $compose = $appConfig->replacingCompose();
        if ($compose !== null) {
            $writes[] = ['path' => EngineArtifacts::APP_CONFIG_COMPOSE, 'contents' => $compose];
        }

        $written = [];
        foreach ($writes as $snippet) {
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
