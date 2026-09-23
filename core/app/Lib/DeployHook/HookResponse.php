<?php

namespace App\Lib\DeployHook;

/**
 * What the git host is told. Its delivery log shows the status and the body,
 * so the body says what was decided and, when nothing was, why.
 */
final class HookResponse
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
    ) {
    }
}
