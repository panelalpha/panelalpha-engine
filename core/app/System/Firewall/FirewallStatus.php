<?php

namespace App\System\Firewall;

final class FirewallStatus
{
    public function __construct(
        public readonly string $provider,
        public readonly ?bool $enabled,
        public readonly ?string $version = null,
        public readonly ?string $defaultIncoming = null,
        public readonly ?string $defaultOutgoing = null,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * @return array{provider: string, enabled: ?bool, version: ?string, default_incoming: ?string, default_outgoing: ?string, error: ?string}
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
        ];
    }
}
