<?php

namespace App\Lib\DeployHook;

use Illuminate\Support\Carbon;

/**
 * A webhook the git provider accepted, as the engine remembers it: which
 * provider, the provider's own id for the hook, and when it was made.
 */
final class ProviderRegistration
{
    public function __construct(
        public readonly string $provider,
        public readonly string $hookId,
        public readonly Carbon $registeredAt,
    ) {}
}
