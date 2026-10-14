<?php

namespace App\Integrations\Tunnels;

use App\Exceptions\TunnelHostnameUnavailableException;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Lib\Apis\PanelAlpha\PanelAlphaException;
use App\Lib\Domains\DomainAllocator;
use App\Models\Domain;
use App\Models\Tunnel;
use App\Models\User;
use App\System;
use Carbon\CarbonInterface;
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
    /** The prefix of the labels the engine allocates for a tunnel it names itself. */
    public const PREVIEW_LABEL_PREFIX = 'preview-';

    /** Random characters in an allocated label, drawn from [a-z0-9]. */
    private const PREVIEW_LABEL_LENGTH = 8;

    /** Labels tried before an allocation gives up and answers `tunnel_hostname_unavailable`. */
    private const PREVIEW_ATTEMPTS = 5;

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
        string $provider = Tunnel::PROVIDER_CLOUDFLARE,
        string $purpose = Tunnel::PURPOSE_DEFAULT,
        ?CarbonInterface $expiresAt = null
    ): Tunnel {
        [$hostname, $provider] = self::assertCreatable($user, $domain, $hostname, $provider);

        $tunnel = self::createWithProvider($user, $domain, $hostname, $provider, $purpose === Tunnel::PURPOSE_PREVIEW);
        self::recordLifetime($tunnel, $purpose, $expiresAt);

        return $tunnel;
    }

    /**
     * Allocate a free `preview-<8>` label under panelalpha.online and create
     * the tunnel.
     *
     * A label is first come, first served across the fleet, so a collision --
     * local or at the proxy -- is retried with a new draw rather than put to
     * the caller. Only when every attempt is taken does the caller hear
     * `tunnel_hostname_unavailable`.
     */
    public static function createAllocatedTunnel(
        User $user,
        Domain $domain,
        string $purpose = Tunnel::PURPOSE_DEFAULT,
        ?CarbonInterface $expiresAt = null
    ): Tunnel {
        for ($attempt = 0; $attempt < self::PREVIEW_ATTEMPTS; $attempt++) {
            $hostname = self::previewHostname();

            try {
                return self::createTunnel(
                    $user,
                    $domain,
                    $hostname,
                    Tunnel::PROVIDER_PANELALPHA,
                    $purpose,
                    $expiresAt
                );
            } catch (CloudflareException | PanelAlphaException $e) {
                if (!DomainAllocator::nameIsTaken($e->getMessage())) {
                    throw $e;
                }
            }
        }

        throw new TunnelHostnameUnavailableException(self::PREVIEW_ATTEMPTS);
    }

    /** A random `preview-<8 [a-z0-9]>.panelalpha.online` name. */
    public static function previewHostname(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $label = self::PREVIEW_LABEL_PREFIX;
        for ($i = 0; $i < self::PREVIEW_LABEL_LENGTH; $i++) {
            $label .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $label . '.' . PanelAlphaConnect::PARENT_DOMAIN;
    }

    private static function createWithProvider(
        User $user,
        Domain $domain,
        string $hostname,
        string $provider,
        bool $preview
    ): Tunnel {
        if ($provider === Tunnel::PROVIDER_CLOUDFLARE) {
            return Cloudflare::createHostname($user, $domain, $hostname);
        }

        if ($provider === Tunnel::PROVIDER_PANELALPHA) {
            $tunnel = PanelAlphaConnect::createTunnel($user, $domain, $hostname, forwardDomain: $preview);
            self::rebuildForSibling($domain, $tunnel);

            return $tunnel;
        }

        throw new CloudflareException("Unsupported tunnel provider '{$provider}'.");
    }

    /**
     * Write the engine's own lifetime and intent. The proxy's `valid_until` --
     * the expiry Connect put on its site -- is a different value and is left as
     * the provider wrote it; only `expires_at` drives expiry here.
     */
    private static function recordLifetime(Tunnel $tunnel, string $purpose, ?CarbonInterface $expiresAt): void
    {
        $tunnel->setDetails(array_filter([
            'purpose' => $purpose,
            'expires_at' => $expiresAt?->toIso8601String(),
        ], static fn (?string $value): bool => $value !== null));
        $tunnel->save();
    }

    /**
     * Remove a public tunnel hostname: the remote registration (best-effort),
     * the local row, and the domain's vhost aliases.
     */
    public static function removeTunnel(User $user, Tunnel $tunnel): void
    {
        $domain = $tunnel->domain;
        self::forgetTunnel($user, $tunnel);

        if ($domain instanceof Domain) {
            self::rebuildForSibling($domain, $tunnel);
        }
    }

    /** Drop the registration and the row, leaving the domain's aliases alone. */
    private static function forgetTunnel(User $user, Tunnel $tunnel): void
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

    /**
     * A panelalpha.online name beside the domain (`api-<name>`) is forwarded
     * with its own Host, so the domain's site starts or stops answering to it.
     */
    private static function rebuildForSibling(Domain $domain, Tunnel $tunnel): void
    {
        if (!$tunnel->isPanelAlpha()
            || PanelAlphaConnect::servesItsOwnPublicName((string) $domain->domain, (string) $tunnel->hostname, Tunnel::PROVIDER_PANELALPHA)
        ) {
            return;
        }
        $domain->projectDomain()->rebuild();
        (new System())->reloadWebserver();
    }

    public static function teardownDomain(User $user, Domain $domain): void
    {
        foreach (Tunnel::forDomain($domain) as $tunnel) {
            try {
                // The domain is going away: nothing to rebuild.
                self::forgetTunnel($user, $tunnel);
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
