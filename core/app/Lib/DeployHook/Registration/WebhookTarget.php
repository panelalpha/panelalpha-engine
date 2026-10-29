<?php

namespace App\Lib\DeployHook\Registration;

/**
 * Where one provider's webhook API lives for a repository: the base URL of the
 * API and the repository as that API spells it (`owner/name`).
 */
final class WebhookTarget
{
    public function __construct(
        public readonly string $apiBase,
        public readonly string $repository,
    ) {}
}
