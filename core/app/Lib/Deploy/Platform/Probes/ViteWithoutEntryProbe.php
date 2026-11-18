<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * A root whose `vite build` has no entry to build: no index.html beside package.json and no vite.config naming
 * another root, input or library, while the build is the engine's `npx vite build` or the script's plain one.
 * Vite is then a tool of the repository (the app sits in `ui/`, or it is only Vitest), and every such build
 * fails on `Could not resolve entry module "index.html"`; read by the Vite platform's `none:` list.
 */
final class ViteWithoutEntryProbe implements PlatformProbe
{
    /** A vite.config that may point the build elsewhere: another root, a Rollup input, a library, an html template. */
    private const ENTRY_ELSEWHERE = '/\b(?:root|input|lib|entry|template)\s*:|\.html\b/';

    /** A build script that is Vite's own default build, with no config of its own named. */
    private const PLAIN_VITE_BUILD = '/^vite\s+build(?![^&|;]*\s(?:-c|--config)\b)(?:\s+[^&|;]*)?$/';

    public function id(): string
    {
        return 'vite-without-entry';
    }

    public function evaluate(ProjectContext $context): bool
    {
        if ($context->hasFile('index.html') || ! self::buildsWithDefaultEntry($context->script('build'))) {
            return false;
        }

        return ! self::configNamesAnotherEntry($context);
    }

    private static function buildsWithDefaultEntry(string $build): bool
    {
        return $build === '' || preg_match(self::PLAIN_VITE_BUILD, $build) === 1;
    }

    /** Unreadable counts as naming one: only a config read in full can say it names none. */
    private static function configNamesAnotherEntry(ProjectContext $context): bool
    {
        if (! $context->hasConfigStem('vite.config')) {
            return false;
        }
        $config = $context->configContents('vite.config');

        return $config === null || $config === '' || preg_match(self::ENTRY_ELSEWHERE, $config) === 1;
    }
}
