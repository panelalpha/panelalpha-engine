<?php

namespace App\Integrations\Tunnels;

use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Models\Domain;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Shared tunnel entry point: guards + dispatch to Cloudflare or PanelAlpha Online.
 *
 * Provider-specific work lives in {@see Cloudflare} and {@see PanelAlphaConnect}.
 * Controllers and console commands call this class so create/delete/sync rules
 * stay in one place.
 */
class TunnelManager
{
    /**
     * Everything that must hold before a tunnel is worth attempting, in one
     * place because two callers ask: the API, and the console command that
     * wants to fail before it prints a confirmation prompt. Whatever is
     * enforced here is enforced for both by construction, rather than by two
     * lists of checks agreeing with each other.
     *
     * Returns the normalised pair, so a caller never has to re-trim what it
     * just handed over.
     *
     * @return array{0: string, 1: string} hostname, provider
     */
    public static function assertCreatable(
        User $user,
        Domain $domain,
        string $hostname,
        string $provider = Tunnel::PROVIDER_CLOUDFLARE
    ): array {
        $hostname = strtolower(trim($hostname));
        $provider = strtolower(trim($provider));

        if ($hostname === '') {
            throw new CloudflareException('Tunnel hostname is required.');
        }
        if (!in_array($provider, Tunnel::PROVIDERS, true)) {
            throw new CloudflareException(
                "Unsupported tunnel provider '{$provider}'. Supported: " . implode(', ', Tunnel::PROVIDERS)
            );
        }
        if ($domain->user_id !== $user->id) {
            throw new CloudflareException('Domain does not belong to this project.');
        }
        // A hostname that is already something else on this engine is a
        // conflict -- except the one arrangement where it is the point: a
        // PanelAlpha Online name attached to the project domain of the same
        // name. WithoutDNS forwards with `Host: <target_domain>`, so making
        // the target the public name is what lets the application see the
        // name its visitors typed, instead of an internal one it will
        // redirect away from. Anything else claiming the name is still a
        // conflict.
        $attachedToItsOwnName = PanelAlphaConnect::servesItsOwnPublicName(
            (string) $domain->domain,
            $hostname,
            $provider
        );

        if (Tunnel::hostnameExists($hostname)
            || (Domain::domainOrAliasExists($hostname) && !$attachedToItsOwnName)
        ) {
            // domainOrAliasExists already includes tunnels; keep message clear.
            throw new CloudflareException("Hostname '{$hostname}' is already in use.");
        }

        // Provider-specific preconditions, asked here rather than discovered
        // halfway through creating something: a Cloudflare tunnel needs the
        // project's API token, and a PanelAlpha Online hostname has to be a
        // single label under the zone the proxy serves.
        if ($provider === Tunnel::PROVIDER_CLOUDFLARE) {
            Cloudflare::clientFor($user);
        }
        if ($provider === Tunnel::PROVIDER_PANELALPHA) {
            PanelAlphaConnect::assertPanelAlphaOnlineHostname($hostname);
        }

        return [$hostname, $provider];
    }

    public static function createTunnel(
        User $user,
        Domain $domain,
        string $hostname,
        string $provider = Tunnel::PROVIDER_CLOUDFLARE
    ): Tunnel {
        [$hostname, $provider] = self::assertCreatable($user, $domain, $hostname, $provider);

        if ($provider === Tunnel::PROVIDER_CLOUDFLARE) {
            return Cloudflare::createHostname($user, $domain, $hostname);
        }

        if ($provider === Tunnel::PROVIDER_PANELALPHA) {
            return PanelAlphaConnect::createTunnel($user, $domain, $hostname);
        }

        throw new CloudflareException("Unsupported tunnel provider '{$provider}'.");
    }

    /**
     * Remove a public tunnel hostname.
     * Cloudflare: DNS + ingress + DB row. Connector stops when no CF tunnels remain.
     * PanelAlpha Online: remote DELETE on license proxy (best-effort) + local DB row.
     */
    public static function deleteTunnel(User $user, Tunnel $tunnel): void
    {
        if ((int) $tunnel->user_id !== (int) $user->id) {
            throw new CloudflareException('Tunnel does not belong to this project.');
        }

        $wasCloudflare = $tunnel->isCloudflare();

        if ($wasCloudflare) {
            Cloudflare::teardownHostname($user, $tunnel);
        } elseif ($tunnel->isPanelAlpha()) {
            PanelAlphaConnect::deleteTunnelRemote($tunnel);
        }

        $tunnel->delete();

        if ($wasCloudflare && !Tunnel::projectHasCloudflareTunnels($user)) {
            Cloudflare::disableConnector($user);
        }
    }

    public static function teardownDomain(User $user, Domain $domain): void
    {
        foreach (Tunnel::forDomain($domain) as $tunnel) {
            try {
                self::deleteTunnel($user, $tunnel);
            } catch (\Throwable $e) {
                Log::warning(
                    "Tunnel teardown failed for {$tunnel->hostname}: " . $e->getMessage()
                );
                try {
                    $tunnel->delete();
                } catch (\Throwable $ignored) {
                    // best-effort
                }
            }
        }
    }

    /**
     * After ProxyRule changes: push upstream into provider-specific tunnel config.
     * Cloudflare: ingress + DNS. PanelAlpha Online: no-op (Connect does not track upstream port).
     */
    public static function syncFromProxyRules(User $user, Domain $domain): void
    {
        Cloudflare::syncFromProxyRules($user, $domain);
    }
}
