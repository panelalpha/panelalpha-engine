<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * A React Router framework app that builds a server: `@react-router/dev` or a react-router.config, unless that
 * config sets `ssr: false`, which builds only `build/client` for a static host.
 */
final class ReactRouterServerProbe implements PlatformProbe
{
    public function id(): string
    {
        return 'react-router-server';
    }

    public function evaluate(ProjectContext $context): bool
    {
        $config = $context->configContents('react-router.config');
        if ($config === null && ! $context->hasDep('@react-router/dev')) {
            return false;
        }

        return preg_match('/\bssr\s*:\s*false\b/', $config ?? '') !== 1;
    }
}
