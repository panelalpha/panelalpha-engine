<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Is this SvelteKit project built with adapter-static?
 *
 * svelte.config decides when it names one adapter: golty lists adapter-static
 * in devDependencies but configures adapter-auto. Without a readable answer
 * there, the dependencies do, as before.
 */
final class SvelteKitStaticAdapterProbe implements PlatformProbe
{
    public function id(): string
    {
        return 'sveltekit-static-adapter';
    }

    public function evaluate(ProjectContext $context): bool
    {
        $configured = SvelteKitAdapter::configured($context);
        if ($configured !== null) {
            return $configured === 'static';
        }

        return $context->hasDep('@sveltejs/adapter-static') && !$context->hasDep('@sveltejs/adapter-node');
    }
}
