<?php

namespace App\Lib\DeployHook;

use App\Models\DeployHook;

/**
 * What rotating a hook produced: the same hook under a new address, and the
 * new secret -- shown this once, like the first one was.
 */
final class HookRotation
{
    public function __construct(
        public readonly DeployHook $hook,
        public readonly string $secret,
    ) {
    }
}
