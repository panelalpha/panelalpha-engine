<?php

namespace App\Lib\Domains;

/**
 * The name a project got, with whether anyone can open it, which certificate it is served and why it
 * is not the preferred name; reported on the project as `details.domain`.
 */
final class AllocatedDomain
{
    /**
     * @param  string  $source  one of DomainPlan's SOURCE_* constants
     * @param  ?bool  $publiclyResolvable  null where it depends on DNS the engine does not control
     * @param  string  $tlsTerminatedAt  `proxy` where the WithoutDNS proxy's certificate is what visitors see, else `engine`
     * @param  ?array{path: string, path_fqdn: string, wdns_site_id: ?int, valid_until: ?string, target_ip: string}  $allocation  what the proxy registered
     */
    public function __construct(
        public readonly string $domain,
        public readonly string $source,
        public readonly ?string $tunnelProvider = null,
        public readonly ?bool $publiclyResolvable = null,
        public readonly string $tlsTerminatedAt = 'engine',
        public readonly ?string $fallbackReason = null,
        public readonly ?array $allocation = null,
    ) {}

    /** The `details.domain` block on the project record. */
    public function toDetails(): array
    {
        return [
            'source' => $this->source,
            'publicly_resolvable' => $this->publiclyResolvable,
            'tls_terminated_at' => $this->tlsTerminatedAt,
            'tunnel' => $this->tunnelProvider,
            'valid_until' => $this->allocation['valid_until'] ?? null,
            'fallback_reason' => $this->fallbackReason,
        ];
    }
}
