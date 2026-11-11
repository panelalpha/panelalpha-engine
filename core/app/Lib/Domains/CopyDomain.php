<?php

namespace App\Lib\Domains;

use App\Integrations\Tunnels\PanelAlphaConnect;
use App\Lib\Helper;
use App\Lib\Project\PublicRouting\HostDomain;
use App\Models\Domain;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The name a copy is created under: `staging.` before its source's, or, below panelalpha.online (whose
 * certificate covers one label), a name {@see claim()} buys from {@see DomainAllocator} once local checks pass.
 */
final class CopyDomain
{
    private ?AllocatedDomain $allocated = null;

    private function __construct(private ?string $name) {}

    /**
     * Nothing is bought here. Null when no free `staging` name is left in
     * front of the source's.
     */
    public static function forCopy(string $sourceDomain, ?string $requested): ?self
    {
        if ($requested !== null && $requested !== '') {
            return new self(Str::startsWith($requested, 'www.') ? Str::after($requested, 'www.') : $requested);
        }
        if (DomainPlan::inOnlineZone($sourceDomain)) {
            return new self(null);
        }
        $name = Helper::generateCloneDomain($sourceDomain);

        return $name === null ? null : new self($name);
    }

    /**
     * Why the copy may not take its name, or null: a name already on the engine, or the server's own that
     * $destUsername may not hold. A name still to be allocated is neither: the allocator only offers free ones.
     *
     * @return ?array{code: string, message: string}
     */
    public function refusal(string $destUsername): ?array
    {
        if ($this->name === null) {
            return null;
        }
        if (Domain::domainOrAliasExists($this->name)) {
            return ['code' => 'domain_taken', 'message' => 'Domain already exists.'];
        }

        return HostDomain::claimRefusal($destUsername, $this->name);
    }

    /** The copy's name, allocating it now when it has to be bought. */
    public function claim(string $destUsername): string
    {
        if ($this->name === null) {
            $this->allocated = app(DomainAllocator::class)->allocate($destUsername);
            $this->name = $this->allocated->domain;
        }

        return $this->name;
    }

    /**
     * Record an allocated name on the copy once its main domain exists: where
     * it came from, and the proxy registration it was bought with.
     */
    public function attachTo(User $dest): void
    {
        if ($this->allocated === null) {
            return;
        }
        $dest->setDetails(['domain' => $this->allocated->toDetails()]);
        $dest->save();
        $domain = $dest->domains()->where('domain', $this->name)->first();
        if ($domain !== null && $this->boughtFromProxy()) {
            PanelAlphaConnect::recordPanelAlphaTunnel($dest, $domain, (array) $this->allocated->allocation);
        }
    }

    /** A name bought by {@see claim()} for a copy that was then not created goes back to the proxy. */
    public function release(): void
    {
        if ($this->allocated !== null) {
            PanelAlphaConnect::releaseAllocated($this->allocated);
        }
    }

    private function boughtFromProxy(): bool
    {
        return $this->allocated?->tunnelProvider === Tunnel::PROVIDER_PANELALPHA
            && $this->allocated->allocation !== null;
    }
}
