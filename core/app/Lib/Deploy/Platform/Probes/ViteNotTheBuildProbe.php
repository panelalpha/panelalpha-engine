<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\JsPackageManager;

/**
 * A workspace root that keeps Vite for its tooling while its `build` is
 * something else: fluxer's runs `cargo`, univer-workspace's `pnpm -r build`,
 * vendure's `lerna run build`. Read by the Vite platform's `none:` list.
 *
 * Only a root with no vite.config of its own: Vite's output is read from that
 * config, so a root without one never produced the SPA the platform serves.
 */
final class ViteNotTheBuildProbe implements PlatformProbe
{
    // `npm run x`, `pnpm x`, `pnpm run x`, `yarn x`, `yarn run x` naming a root script.
    private const SCRIPT_CALL = '/(?:^|&&|\|\||;)\s*(?:npm\s+run|pnpm(?:\s+run)?|yarn(?:\s+run)?)\s+([\w:.-]+)/';

    public function id(): string
    {
        return 'vite-not-the-build';
    }

    public function evaluate(ProjectContext $context): bool
    {
        if ($context->hasConfigStem('vite.config')) {
            return false;
        }
        if (!JsPackageManager::isJsWorkspace($context->package() ?? [], $context->files)) {
            return false;
        }
        $build = $context->script('build');

        return $build !== '' && !self::runsVite($context, $build, 0);
    }

    /** Does $command, following root scripts it calls a few levels, mention vite? */
    private static function runsVite(ProjectContext $context, string $command, int $depth): bool
    {
        if (preg_match('/(?<![\w@\/-])vite(?![\w-])/', $command) === 1) {
            return true;
        }
        if ($depth >= 3 || preg_match_all(self::SCRIPT_CALL, $command, $calls) === 0) {
            return false;
        }
        foreach ($calls[1] as $name) {
            $body = $context->script($name);
            if ($body !== '' && self::runsVite($context, $body, $depth + 1)) {
                return true;
            }
        }

        return false;
    }
}
