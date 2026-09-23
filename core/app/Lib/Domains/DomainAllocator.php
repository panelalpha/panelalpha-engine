<?php

namespace App\Lib\Domains;

use App\Integrations\Tunnels\PanelAlphaConnect;
use App\Lib\Apis\PanelAlpha\PanelAlphaException;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\Tunnel;
use Illuminate\Support\Facades\Log;

/**
 * Walks {@see DomainPlan}'s ladder until a name is actually free, and pays
 * for it where the name has to be bought.
 *
 * This is the impure half: it reads settings, asks the database whether a
 * name is taken, and calls Connect's proxy. What it must never do is
 * fail. A project whose preferred name was unavailable still gets a name --
 * one rung down, with `fallback_reason` saying which rung was skipped and
 * why. The single exception is a name the caller typed itself: silently
 * substituting something else for that would be worse than an error.
 */
class DomainAllocator
{
    /** Labels to try under panelalpha.online before giving up on the rung. */
    private const ONLINE_ATTEMPTS = 5;

    /** Suffixed names to try on a rung whose name is already used locally. */
    private const LOCAL_ATTEMPTS = 10;

    /**
     * @param ?string $requested the caller's `domain`
     * @param ?string $tunnel the caller's `tunnel`
     * @throws DomainAllocationException when a name the caller named cannot be had
     */
    public function allocate(string $username, ?string $requested = null, ?string $tunnel = null): AllocatedDomain
    {
        $settings = self::settings();

        // Seeded before the walk, because a rung that was never a candidate
        // is invisible to it: on a host with no public address neither
        // PanelAlpha zone is offered at all, and without this the project
        // lands on `.local` reporting no reason for it.
        $skipped = [];
        if ($requested === null && ($reason = DomainPlan::noPublicNameReason($settings)) !== null) {
            $skipped[] = $reason;
        }

        foreach (DomainPlan::candidates($username, $requested, $tunnel, $settings) as $candidate) {
            $requestedByCaller = $candidate['source'] === DomainPlan::SOURCE_REQUESTED;

            if ($candidate['label'] !== null && $tunnel !== DomainPlan::TUNNEL_NONE) {
                $allocated = $this->allocateOnline(
                    $candidate['label'],
                    (string) $settings['default_ipv4'],
                    $requestedByCaller,
                    $skipped
                );
                if ($allocated !== null) {
                    return $allocated;
                }

                continue;
            }

            // A name the caller typed is handed back as it is: whether it is
            // free is the create call's own check, and its error names the
            // field. Everything generated has to be free before it is offered.
            $domain = $requestedByCaller
                ? $candidate['domain']
                : $this->firstFreeLocally($candidate['domain']);

            if ($domain === null) {
                $skipped[] = "{$candidate['source']}: every name tried is already on this engine";
                continue;
            }

            return new AllocatedDomain(
                domain: $domain,
                source: $candidate['source'],
                publiclyResolvable: self::resolvability($candidate['source'], $settings),
                fallbackReason: $skipped === [] ? null : implode(' | ', $skipped),
            );
        }

        // candidates() always ends with `<name>.local`, which needs neither a
        // network nor a setting, so this is unreachable by construction.
        throw new DomainAllocationException(
            "No domain could be chosen for project '{$username}'.",
            'no_domain_available'
        );
    }

    /**
     * Buy a label under panelalpha.online, retrying a random suffix while the
     * proxy says the name is taken.
     *
     * Returns null when the rung is not to be had at all -- the proxy is
     * unreachable, the engine is unlicensed -- so the caller drops to the
     * next one. A caller that named the label itself gets the error instead:
     * it asked for that name, not for a name.
     *
     * @param list<string> $skipped collects why a rung was passed over
     */
    private function allocateOnline(
        string $label,
        string $targetIp,
        bool $requestedByCaller,
        array &$skipped
    ): ?AllocatedDomain {
        $attempts = $requestedByCaller ? 1 : self::ONLINE_ATTEMPTS;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $fqdn = $label . '.' . DomainPlan::PANELALPHA_ONLINE;

            if (self::takenLocally($fqdn)) {
                if ($requestedByCaller) {
                    throw new DomainAllocationException(
                        "{$fqdn} is already on this engine.",
                        'hostname_taken'
                    );
                }
                $label = DomainPlan::suffixed($label);
                continue;
            }

            try {
                // The proxy forwards with the Host of the domain it is told to
                // target, so the target is the public name itself. That is what
                // lets the application be installed under the name visitors type.
                $created = (new PanelAlphaConnect())->createSite($fqdn, $targetIp, $label);
            } catch (PanelAlphaException $e) {
                if (self::nameIsTaken($e->getMessage())) {
                    if ($requestedByCaller) {
                        throw new DomainAllocationException(
                            "{$fqdn} is already taken. Labels under "
                            . DomainPlan::PANELALPHA_ONLINE
                            . ' are first come, first served across every engine.',
                            'hostname_taken'
                        );
                    }
                    $label = DomainPlan::suffixed($label);
                    continue;
                }

                if ($requestedByCaller) {
                    throw new DomainAllocationException($e->getMessage(), 'allocation_failed');
                }

                Log::info("PanelAlpha Online allocation unavailable: {$e->getMessage()}");
                $skipped[] = DomainPlan::SOURCE_PANELALPHA_ONLINE . ': ' . $e->getMessage();

                return null;
            }

            return new AllocatedDomain(
                domain: $created['path_fqdn'],
                source: $requestedByCaller
                    ? DomainPlan::SOURCE_REQUESTED
                    : DomainPlan::SOURCE_PANELALPHA_ONLINE,
                tunnelProvider: Tunnel::PROVIDER_PANELALPHA,
                publiclyResolvable: true,
                tlsTerminatedAt: 'proxy',
                fallbackReason: $skipped === [] ? null : implode(' | ', $skipped),
                allocation: $created + ['target_ip' => $targetIp],
            );
        }

        $skipped[] = DomainPlan::SOURCE_PANELALPHA_ONLINE
            . ': every label tried is already registered';

        return null;
    }

    /** The candidate name, or a suffixed one, that nothing on this engine holds. */
    private function firstFreeLocally(string $domain): ?string
    {
        if (!self::takenLocally($domain)) {
            return $domain;
        }

        [$label, $parent] = array_pad(explode('.', $domain, 2), 2, '');

        for ($attempt = 0; $attempt < self::LOCAL_ATTEMPTS; $attempt++) {
            $candidate = DomainPlan::suffixed($label) . '.' . $parent;
            if (!self::takenLocally($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function takenLocally(string $domain): bool
    {
        return Domain::domainOrAliasExists($domain) || Tunnel::hostnameExists($domain);
    }

    /**
     * Whether the proxy refused the label because someone else has it, as
     * opposed to refusing to talk to us at all. The first is a reason to try
     * another name; the second is a reason to stop trying this zone.
     */
    private static function nameIsTaken(string $message): bool
    {
        return preg_match('/not available|already (in use|exists|taken|registered)|duplicate|taken/i', $message) === 1;
    }

    /**
     * Whether a name on this rung reaches this host from the open internet.
     *
     * Null where it honestly cannot be said: a domain the caller named and
     * the operator's own base domain both depend on DNS this engine does not
     * serve. Guessing `true` there is how a caller ends up reporting a URL
     * that answers for nobody.
     */
    /**
     * @param array{sites_base_domain: ?string, cert_domain: ?string, default_ipv4: ?string} $settings
     */
    private static function resolvability(string $source, array $settings): ?bool
    {
        return match ($source) {
            DomainPlan::SOURCE_PANELALPHA_ONLINE => true,
            // A `.direct` name is the address it spells. Publicly resolvable
            // is about the address, not the zone: on a host whose address is
            // private, the name resolves everywhere and answers on that LAN
            // alone -- which is exactly what the warning has to say.
            DomainPlan::SOURCE_PANELALPHA_DIRECT => DomainPlan::hasPublicIpv4($settings),
            DomainPlan::SOURCE_LOCAL => false,
            default => null,
        };
    }

    /**
     * @return array{sites_base_domain: ?string, cert_domain: ?string, default_ipv4: ?string}
     */
    private static function settings(): array
    {
        return [
            'sites_base_domain' => Setting::get('default_wildcard_domain'),
            'cert_domain' => Setting::get('cert_domain'),
            'default_ipv4' => Setting::get('default_ipv4'),
        ];
    }
}
