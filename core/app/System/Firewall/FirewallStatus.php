<?php

namespace App\System\Firewall;

final class FirewallStatus
{
    /**
     * @param  list<array{id: ?string, rule: string, error: string}>  $refusedRules  stored rules the
     *                                                                               firewall would not load: `id` is the listed rule to delete, null when none is
     */
    public function __construct(
        public readonly string $provider,
        public readonly ?bool $enabled,
        public readonly ?string $version = null,
        public readonly ?string $defaultIncoming = null,
        public readonly ?string $defaultOutgoing = null,
        public readonly ?string $error = null,
        public readonly array $refusedRules = [],
    ) {}

    /**
     * @return array{provider: string, enabled: ?bool, version: ?string, default_incoming: ?string, default_outgoing: ?string, error: ?string, refused_rules: list<array{id: ?string, rule: string, error: string}>}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'enabled' => $this->enabled,
            'version' => $this->version,
            'default_incoming' => $this->defaultIncoming,
            'default_outgoing' => $this->defaultOutgoing,
            'error' => $this->error,
            'refused_rules' => $this->refusedRules,
        ];
    }
}
