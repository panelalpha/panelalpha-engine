<?php

namespace App\Lib\DeployHook;

/**
 * A webhook the git provider accepted, as the engine remembers it: which
 * provider and the provider's own id for the hook.
 */
final class ProviderRegistration
{
    public function __construct(
        public readonly string $provider,
        public readonly string $hookId,
    ) {}
}
