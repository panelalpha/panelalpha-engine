<?php

namespace App\Lib\Domains;

use App\Integrations\Tunnels\PanelAlphaConnect;
use App\Lib\Helper;
use App\Models\Domain;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The name a staging copy or a clone is created under.
 *
 * Without one asked for, a copy is named `staging.` in front of its source's
 * name. Not below panelalpha.online: that zone is the proxy's and its
 * certificate covers one label, so the copy could not be opened over HTTPS.
 * A copy of a project there gets a name of its own from
 * {@see DomainAllocator}, the way a new project does. That name is bought,
 * so it is only allocated by {@see claim()}, once every local check passed.
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

    /** A name still to be allocated is not: the allocator only offers free ones. */
    public function isTaken(): bool
    {
        return $this->name !== null && Domain::domainOrAliasExists($this->name);
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
