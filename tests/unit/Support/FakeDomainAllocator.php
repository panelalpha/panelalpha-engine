<?php

namespace Tests\Support;

use App\Lib\Domains\AllocatedDomain;
use App\Lib\Domains\DomainAllocator;
use App\Lib\Domains\DomainPlan;
use App\Models\Tunnel;

/** Sells `<username>-9f0e.panelalpha.online` without a proxy, and remembers who asked. */
class FakeDomainAllocator extends DomainAllocator
{
    /** @var list<string> */
    public array $asked = [];

    public function allocate(string $username, ?string $requested = null, ?string $tunnel = null): AllocatedDomain
    {
        $this->asked[] = $username;
        $fqdn = $username.'-9f0e.'.DomainPlan::PANELALPHA_ONLINE;

        return new AllocatedDomain(
            domain: $fqdn,
            source: DomainPlan::SOURCE_PANELALPHA_ONLINE,
            tunnelProvider: Tunnel::PROVIDER_PANELALPHA,
            publiclyResolvable: true,
            tlsTerminatedAt: 'proxy',
            allocation: ['path' => $username.'-9f0e', 'path_fqdn' => $fqdn, 'wdns_site_id' => 7, 'valid_until' => null, 'target_ip' => '203.0.113.5'],
        );
    }
}
