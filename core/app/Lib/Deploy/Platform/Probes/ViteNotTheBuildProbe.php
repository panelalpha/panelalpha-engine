<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use App\Lib\Deploy\Platform\Runtime\ScriptChain;

/**
 * A workspace root without a vite.config that keeps Vite for tooling while its `build` is something
 * else (`cargo`, `lerna run build`); read by the Vite platform's `none:` list.
 */
final class ViteNotTheBuildProbe implements PlatformProbe
{
    public function id(): string
    {
        return 'vite-not-the-build';
    }

    public function evaluate(ProjectContext $context): bool
    {
        if ($context->hasConfigStem('vite.config')) {
            return false;
        }
        if (! JsPackageManager::isJsWorkspace($context->package() ?? [], $context->files)) {
            return false;
        }
        $build = $context->script('build');

        return $build !== '' && ! ScriptChain::mentions($build, '/(?<![\w@\/-])vite(?![\w-])/', $context->script(...));
    }
}
