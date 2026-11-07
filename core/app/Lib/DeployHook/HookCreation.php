<?php

namespace App\Lib\DeployHook;

use App\Models\DeployHook;

/**
 * What creating a hook produced; `secret` is set only when this call made the hook, the one time the plaintext
 * leaves the engine, so a repeat call gets the same hook and no secret.
 */
final class HookCreation
{
    public function __construct(
        public readonly DeployHook $hook,
        public readonly ?string $secret,
        public readonly bool $created,
    ) {}
}
