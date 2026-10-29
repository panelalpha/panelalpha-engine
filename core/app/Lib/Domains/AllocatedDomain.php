<?php

namespace App\Lib\Domains;

/**
 * The name a project got, and everything a caller needs to know about it
 * without asking a second question.
 *
 * A domain string on its own does not say whether anyone can open it, what
 * certificate it will be served, or why it is not the name that was
 * preferred. Those were the questions a client had to answer out of band --
 * by reading `system_info`, knowing the zones, and inferring. They are fields
 * now: reported on the project as `details.domain`.
 */
final class AllocatedDomain
{
    /**
     * @param  string  $source  one of DomainPlan's SOURCE_* constants
     * @param  ?bool  $publiclyResolvable  null where it depends on DNS the
     *                                     engine does not control, and so cannot be answered here
     * @param  string  $tlsTerminatedAt  `proxy` for a name the WithoutDNS proxy
     *                                   serves -- its certificate is the trusted one visitors see, and
     *                                   the engine's own is irrelevant -- `engine` for everything else
     * @param  ?array{path: string, path_fqdn: string, wdns_site_id: ?int, valid_until: ?string, target_ip: string}  $allocation
     *                                                                                                                            what the proxy registered, for the tunnel row the caller writes
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
