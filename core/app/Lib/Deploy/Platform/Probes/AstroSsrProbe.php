<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Whether an Astro project builds a Node server rather than HTML: `@astrojs/node`, `output: server`/`hybrid`,
 * or a `start` script that is not the local preview.
 */
final class AstroSsrProbe implements PlatformProbe
{
    public function id(): string
    {
        return 'astro-ssr';
    }

    /** An explicit `output: 'static'` settles it whatever else is present; `start: astro dev` is only the preview. */
    public function evaluate(ProjectContext $context): bool|array
    {
        $config = $context->configContents('astro.config') ?? '';

        if (preg_match('/output\s*:\s*[\'"]static[\'"]/', $config) === 1) {
            return false;
        }

        if ($context->hasDep('@astrojs/node')
            || preg_match('/output\s*:\s*[\'"](server|hybrid)[\'"]/', $config) === 1
        ) {
            return true;
        }

        $start = $context->script('start');

        return $start !== '' && preg_match('/\bastro\s+(dev|preview)\b/', $start) !== 1;
    }
}
