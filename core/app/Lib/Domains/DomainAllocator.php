<?php

namespace App\Lib\Domains;

use App\Integrations\Tunnels\PanelAlphaConnect;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Lib\Apis\PanelAlpha\PanelAlphaException;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\Tunnel;
use Illuminate\Support\Facades\Log;

/**
 * Walks {@see DomainPlan}'s ladder until a name is free, buying it where it must be bought.
 * Never fails, except for a name the caller typed itself.
 */
class DomainAllocator
{
    public const SETTING_ONLINE_LAST_ERROR = 'panelalpha_online_last_error';

    /** Labels to try under panelalpha.online before giving up on the rung. */
    private const ONLINE_ATTEMPTS = 5;

    /** Suffixed names to try on a rung whose name is already used locally. */
    private const LOCAL_ATTEMPTS = 10;

    /**
     * @throws DomainAllocationException when a name the caller named cannot be had
     */
    public function allocate(string $username, ?string $requested = null, ?string $tunnel = null): AllocatedDomain
    {
        $settings = self::settings();
        $tunnel = DomainPlan::resolveTunnel($tunnel, $settings);
        $skipped = self::unofferedReasons($requested, $settings);

        foreach (DomainPlan::candidates($username, $requested, $tunnel, $settings) as $candidate) {
            $allocated = $this->allocateCandidate($candidate, $tunnel, $settings, $skipped);
            if ($allocated !== null) {
                return $allocated;
            }
        }

        throw new DomainAllocationException(
            "No domain could be chosen for project '{$username}'.",
            'no_domain_available'
        );
    }

    /**
     * Seeded before the walk: a rung that was never a candidate is otherwise invisible to it.
     *
     * @param  array{sites_base_domain: ?string, cert_domain: ?string, default_ipv4: ?string, default_tunnel: ?string}  $settings
     * @return list<string>
     */
    private static function unofferedReasons(?string $requested, array $settings): array
    {
        $skipped = [];
        if ($requested === null && ($reason = DomainPlan::noPublicNameReason($settings)) !== null) {
            $skipped[] = $reason;
        }

        return $skipped;
    }

    /**
     * A name the caller typed is handed back as it is; everything generated has to be free first.
     *
     * @param  array{domain: string, source: string, label: ?string}  $candidate
     * @param  array{sites_base_domain: ?string, cert_domain: ?string, default_ipv4: ?string, default_tunnel: ?string}  $settings
     * @param  list<string>  $skipped
     */
    private function allocateCandidate(array $candidate, ?string $tunnel, array $settings, array &$skipped): ?AllocatedDomain
    {
        $requestedByCaller = $candidate['source'] === DomainPlan::SOURCE_REQUESTED;

        if ($candidate['label'] !== null && $tunnel !== DomainPlan::TUNNEL_NONE) {
            return $this->allocateOnline(
                $candidate['label'],
                (string) $settings['default_ipv4'],
                $requestedByCaller,
                $skipped
            );
        }

        $domain = $requestedByCaller
            ? $candidate['domain']
            : $this->firstFreeLocally($candidate['domain']);

        if ($domain === null) {
            $skipped[] = "{$candidate['source']}: every name tried is already on this engine";

            return null;
        }

        return new AllocatedDomain(
            domain: $domain,
            source: $candidate['source'],
            publiclyResolvable: self::resolvability($candidate['source'], $settings),
            fallbackReason: $skipped === [] ? null : implode(' | ', $skipped),
        );
    }

    /**
     * Buy a label under panelalpha.online, retrying a random suffix while it is taken; null drops the rung.
     * The proxy forwards with the Host of its target, so the target is the public name itself.
     *
     * @param  list<string>  $skipped  collects why a rung was passed over
     */
    private function allocateOnline(string $label, string $targetIp, bool $requestedByCaller, array &$skipped): ?AllocatedDomain
    {
        $attempts = $requestedByCaller ? 1 : self::ONLINE_ATTEMPTS;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $fqdn = $label.'.'.DomainPlan::PANELALPHA_ONLINE;

            if (self::takenLocally($fqdn)) {
                self::refuseTakenLocally($fqdn, $requestedByCaller);
                $label = DomainPlan::suffixed($label);

                continue;
            }

            try {
                $created = (new PanelAlphaConnect)->createSite($fqdn, $targetIp, $label);
            } catch (PanelAlphaException $e) {
                if (! self::retriesAfterRefusal($e, $fqdn, $requestedByCaller, $skipped)) {
                    return null;
                }
                $label = DomainPlan::suffixed($label);

                continue;
            }

            return self::soldOnline($created, $targetIp, $requestedByCaller, $skipped);
        }

        $skipped[] = DomainPlan::SOURCE_PANELALPHA_ONLINE.': every label tried is already registered';

        return null;
    }

    private static function refuseTakenLocally(string $fqdn, bool $requestedByCaller): void
    {
        if ($requestedByCaller) {
            throw new DomainAllocationException(
                "{$fqdn} is already on this engine.",
                'hostname_taken'
            );
        }
    }

    private static function refuseTakenAtProxy(string $fqdn, bool $requestedByCaller): void
    {
        if ($requestedByCaller) {
            throw new DomainAllocationException(
                "{$fqdn} is already taken. Labels under "
                .DomainPlan::PANELALPHA_ONLINE
                .' are first come, first served across every engine.',
                'hostname_taken'
            );
        }
    }

    /**
     * True when the proxy says the label is taken, so another may be tried.
     *
     * @param  list<string>  $skipped
     */
    private static function retriesAfterRefusal(PanelAlphaException $e, string $fqdn, bool $requestedByCaller, array &$skipped): bool
    {
        if (self::nameIsTaken($e)) {
            self::refuseTakenAtProxy($fqdn, $requestedByCaller);

            return true;
        }

        self::giveUpOnline($e, $requestedByCaller, $skipped);

        return false;
    }

    /** @param  list<string>  $skipped */
    private static function giveUpOnline(PanelAlphaException $e, bool $requestedByCaller, array &$skipped): void
    {
        self::rememberOnlineError($e->getMessage());

        if ($requestedByCaller) {
            throw new DomainAllocationException($e->getMessage(), 'allocation_failed');
        }

        Log::warning("PanelAlpha Online allocation unavailable: {$e->getMessage()}");
        $skipped[] = DomainPlan::SOURCE_PANELALPHA_ONLINE.': '.$e->getMessage();
    }

    /**
     * @param  array<string, mixed>  $created
     * @param  list<string>  $skipped
     */
    private static function soldOnline(array $created, string $targetIp, bool $requestedByCaller, array $skipped): AllocatedDomain
    {
        self::rememberOnlineError(null);

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

    /** The last time PanelAlpha Online refused or failed, for `GET /system/info`; cleared by the next sale. */
    public static function lastOnlineError(): ?array
    {
        try {
            $stored = Setting::get(self::SETTING_ONLINE_LAST_ERROR);
        } catch (\Throwable) {
            return null;
        }
        $decoded = is_string($stored) && $stored !== '' ? json_decode($stored, true) : null;

        return is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded : null;
    }

    /** Advisory: never the reason an allocation fails. */
    private static function rememberOnlineError(?string $message): void
    {
        try {
            if ($message === null) {
                if (! in_array(Setting::get(self::SETTING_ONLINE_LAST_ERROR), [null, ''], true)) {
                    Setting::set(self::SETTING_ONLINE_LAST_ERROR, '');
                }

                return;
            }
            Setting::set(self::SETTING_ONLINE_LAST_ERROR, (string) json_encode([
                'message' => $message,
                'at' => now()->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            Log::warning("Could not record the PanelAlpha Online error: {$e->getMessage()}");
        }
    }

    /** The candidate name, or a suffixed one, that nothing on this engine holds. */
    private function firstFreeLocally(string $domain): ?string
    {
        if (! self::takenLocally($domain)) {
            return $domain;
        }

        [$label, $parent] = array_pad(explode('.', $domain, 2), 2, '');

        for ($attempt = 0; $attempt < self::LOCAL_ATTEMPTS; $attempt++) {
            $candidate = DomainPlan::suffixed($label).'.'.$parent;
            if (! self::takenLocally($candidate)) {
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
     * Whether a refusal means the label is taken: on this engine, or at the proxy, which answers a 400, 409 or 422 naming it.
     * Any other refusal is the proxy's, and its own message is what gets recorded.
     */
    public static function nameIsTaken(CloudflareException|PanelAlphaException $e): bool
    {
        return preg_match("/^Hostname '[^']+' is already in use\.$/", $e->getMessage()) === 1
            || ($e instanceof PanelAlphaException && self::takenAtProxy($e));
    }

    private static function takenAtProxy(PanelAlphaException $e): bool
    {
        return in_array($e->responseStatus(), [400, 409, 422], true)
            && preg_match('/domain name is not available|already exists|has already been taken|already registered/i', $e->getMessage()) === 1;
    }

    /**
     * The domains a project created with neither a domain nor a tunnel is named under, best first.
     *
     * @return list<array{base: string, source: string, suffixed: bool, publicly_resolvable: ?bool}>
     */
    public static function projectDomainBases(): array
    {
        $settings = self::settings();

        return array_map(
            static fn (array $base): array => $base + ['publicly_resolvable' => self::resolvability($base['source'], $settings)],
            DomainPlan::bases(null, $settings)
        );
    }

    /**
     * Whether a name on this rung reaches this host from the open internet; null where DNS is not ours.
     * A `.direct` name is the address it spells, so its answer is about the address.
     *
     * @param  array{sites_base_domain: ?string, cert_domain: ?string, default_ipv4: ?string}  $settings
     */
    private static function resolvability(string $source, array $settings): ?bool
    {
        return match ($source) {
            DomainPlan::SOURCE_PANELALPHA_ONLINE => true,
            DomainPlan::SOURCE_PANELALPHA_DIRECT => DomainPlan::hasPublicIpv4($settings),
            DomainPlan::SOURCE_LOCAL => false,
            default => null,
        };
    }

    /**
     * @return array{sites_base_domain: ?string, cert_domain: ?string, default_ipv4: ?string, default_tunnel: ?string}
     */
    private static function settings(): array
    {
        return [
            'sites_base_domain' => Setting::get('default_wildcard_domain'),
            'cert_domain' => Setting::get('cert_domain'),
            'default_ipv4' => Setting::get('default_ipv4'),
            'default_tunnel' => Setting::get(DomainPlan::SETTING_DEFAULT_TUNNEL),
        ];
    }
}
