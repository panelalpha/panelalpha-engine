<?php

namespace App\Lib\DeployHook;

use App\Models\DeployHook;

/**
 * What creating a hook produced.
 *
 * `secret` is set only when this call made the hook. It is the one time the
 * plaintext exists outside the engine, so a caller that asks again gets the
 * same hook and no secret.
 */
final class HookCreation
{
    public function __construct(
        public readonly DeployHook $hook,
        public readonly ?string $secret,
        public readonly bool $created,
    ) {
    }
}
