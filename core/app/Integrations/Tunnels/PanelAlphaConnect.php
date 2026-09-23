<?php

namespace App\Integrations\Tunnels;

use App\Lib\Apis\PanelAlpha;
use App\Lib\Apis\PanelAlpha\PanelAlphaException;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PanelAlpha Connect: WithoutDNS names under *.panelalpha.online.
 *
 * Connect is an integration the engine *calls* — register, update, or release
 * a public label. Telemetry goes somewhere else ({@see \App\Integrations\Monitoring\PanelAlphaMonitoring}).
 *
 * POST create-only; PUT/DELETE require full path FQDN and ownership on Connect.
 * Licensing answers on the same host (`connect.panelalpha.com`).
 */
class PanelAlphaConnect
{
    public const PARENT_DOMAIN = 'panelalpha.online';

    /** The configured Connect URL without its trailing slash, or '' when unset. */
    public static function base(): string
    {
        return rtrim(trim((string) config('connect.url', '')), '/');
    }

    /**
     * Connect this engine registers names with.
     *
     * Unlike telemetry, an empty Connect URL cannot mean "do nothing quietly" here: a
     * caller is asking for a name it intends to serve, and posting that at a
     * relative URL would be worse than saying so. The exception the allocator
     * already handles is the honest answer.
     */
    public static function endpoint(): string
    {
        $base = self::base();
        if ($base === '') {
            throw new PanelAlphaException(
                'No PanelAlpha Connect is configured (PANELALPHA_CONNECT), so *.'
                . self::PARENT_DOMAIN . ' names cannot be registered.'
            );
        }

        return $base;
    }

    /**
     * Extract the single path label from a *.panelalpha.online FQDN (or bare label).
     */
    public static function pathFromHostname(string $hostname): ?string
    {
        $candidate = Str::lower(trim($hostname));
        $candidate = rtrim($candidate, '.');
        $suffix = '.' . self::PARENT_DOMAIN;
        if (Str::endsWith($candidate, $suffix)) {
            $candidate = Str::beforeLast($candidate, $suffix);
        } elseif ($candidate === self::PARENT_DOMAIN) {
            return null;
        } elseif (Str::contains($candidate, '.')) {
            // Other parents are not supported yet.
            return null;
        }

        $candidate = trim($candidate, '.');
        if ($candidate === '' || Str::contains($candidate, '.')) {
            return null;
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $candidate)) {
            return null;
        }

        return $candidate;
    }

    public static function assertPanelAlphaOnlineHostname(string $hostname): string
    {
        $path = self::pathFromHostname($hostname);
        if ($path === null) {
            throw new PanelAlphaException(
                "Hostname must be a single label under *." . self::PARENT_DOMAIN
                . " (got '{$hostname}')."
            );
        }

        return $path;
    }

    public static function pathFqdnFromHostname(string $hostname): string
    {
        $path = self::assertPanelAlphaOnlineHostname($hostname);

        return $path . '.' . self::PARENT_DOMAIN;
    }

    /**
     * Register {path}.panelalpha.online → target_domain @ target_ip via the Connect proxy.
     *
     * @return array{
     *   path: string,
     *   path_fqdn: string,
     *   wdns_site_id: ?int,
     *   valid_until: ?string,
     *   raw: array<string, mixed>
     * }
     */
    public function createSite(string $targetDomain, string $targetIp, string $path): array
    {
        $targetDomain = strtolower(trim($targetDomain));
        $targetIp = trim($targetIp);
        $path = self::assertPanelAlphaOnlineHostname($path);

        if ($targetDomain === '') {
            throw new PanelAlphaException('target_domain is required.');
        }
        if ($targetIp === '' || filter_var($targetIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new PanelAlphaException(
                "A valid public IPv4 (default_ipv4) is required to create a PanelAlpha Online tunnel (got '{$targetIp}')."
            );
        }

        $url = self::endpoint() . '/api/without-dns/sites';
        $payload = [
            'target_domain' => $targetDomain,
            'target_ip' => $targetIp,
            'path' => $path,
            'plugin' => 'PanelAlpha',
        ];

        /** @var Response $response */
        $response = PanelAlpha::http()->post($url, $payload);

        return $this->parseSuccessBody($response, $path, 'create');
    }

    /**
     * Update targets for an owned *.panelalpha.online site (FQDN in URL).
     *
     * @return array{
     *   path: string,
     *   path_fqdn: string,
     *   wdns_site_id: ?int,
     *   valid_until: ?string,
     *   raw: array<string, mixed>
     * }
     */
    public function updateSite(string $pathFqdn, string $targetDomain, string $targetIp): array
    {
        $pathFqdn = self::pathFqdnFromHostname($pathFqdn);
        $path = self::assertPanelAlphaOnlineHostname($pathFqdn);
        $targetDomain = strtolower(trim($targetDomain));
        $targetIp = trim($targetIp);

        if ($targetDomain === '') {
            throw new PanelAlphaException('target_domain is required.');
        }
        if ($targetIp === '' || filter_var($targetIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new PanelAlphaException(
                "A valid public IPv4 (default_ipv4) is required to update a PanelAlpha Online tunnel (got '{$targetIp}')."
            );
        }

        $url = self::endpoint() . '/api/without-dns/sites/' . rawurlencode($pathFqdn);
        /** @var Response $response */
        $response = PanelAlpha::http()->put($url, [
            'target_domain' => $targetDomain,
            'target_ip' => $targetIp,
            'plugin' => 'PanelAlpha',
        ]);

        return $this->parseSuccessBody($response, $path, 'update');
    }

    /**
     * Delete an owned *.panelalpha.online site (FQDN in URL).
     */
    public function deleteSite(string $pathFqdn): void
    {
        $pathFqdn = self::pathFqdnFromHostname($pathFqdn);
        $url = self::endpoint() . '/api/without-dns/sites/' . rawurlencode($pathFqdn);

        /** @var Response $response */
        $response = PanelAlpha::http()->delete($url);
        if ($response->status() === 204 || $response->status() === 404) {
            return;
        }

        $body = $response->json();
        $message = is_array($body) && is_string($body['message'] ?? null)
            ? $body['message']
            : 'delete failed';
        throw new PanelAlphaException(
            "PanelAlpha Online delete failed: {$message} (HTTP {$response->status()})."
        );
    }

    /**
     * Is this a PanelAlpha Online name attached to the project domain that
     * already carries it?
     *
     * The arrangement that lets an application know its own address. Attach
     * the tunnel to a different local domain and WithoutDNS forwards with
     * `Host: <that local domain>`, so the application is installed under a
     * name nobody types and canonicalises visitors straight into a redirect
     * loop -- measured, not feared. Make the project's domain the public name
     * and the forwarded Host is the public name, which is what a visitor
     * asked for and what an installer should bake in.
     */
    public static function servesItsOwnPublicName(
        string $domainName,
        string $hostname,
        string $provider
    ): bool {
        return strtolower(trim($provider)) === Tunnel::PROVIDER_PANELALPHA
            && strtolower(trim($domainName)) === strtolower(trim($hostname));
    }

    /**
     * Register *.panelalpha.online via Connect's WithoutDNS proxy (no DinD / CF token).
     * Caller must have already run {@see TunnelManager::assertCreatable}.
     */
    public static function createTunnel(User $user, Domain $domain, string $hostname): Tunnel
    {
        $path = self::assertPanelAlphaOnlineHostname($hostname);

        $targetIp = trim((string) Setting::get('default_ipv4'));
        if ($targetIp === '' || filter_var($targetIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new PanelAlphaException(
                'default_ipv4 must be set to a valid IPv4 before creating a PanelAlpha Online tunnel.'
            );
        }

        $created = (new self())->createSite($domain->domain, $targetIp, $path);

        return self::recordPanelAlphaTunnel($user, $domain, $created + ['target_ip' => $targetIp]);
    }

    /**
     * Write the row for a panelalpha.online name the proxy has already
     * registered.
     *
     * Split out because creating a project allocates the name *before* there
     * is a domain to attach it to -- a label that turns out to be taken has
     * to be discovered while another one can still be chosen, not after the
     * project has been built around the first guess.
     * {@see \App\Lib\Domains\DomainAllocator} does the buying; this does
     * the recording, so there is still one place that decides what a
     * PanelAlpha Online tunnel row looks like.
     *
     * @param array{path: string, path_fqdn: string, wdns_site_id?: ?int, valid_until?: ?string, target_ip: string} $created
     */
    public static function recordPanelAlphaTunnel(User $user, Domain $domain, array $created): Tunnel
    {
        $details = [
            'path' => $created['path'],
            'target_ip' => $created['target_ip'],
        ];
        if (($created['wdns_site_id'] ?? null) !== null) {
            $details['wdns_site_id'] = $created['wdns_site_id'];
        }
        if (($created['valid_until'] ?? null) !== null) {
            $details['valid_until'] = $created['valid_until'];
        }

        return Tunnel::create([
            'domain_id' => $domain->id,
            'user_id' => $user->id,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => $created['path_fqdn'],
            'details' => $details,
        ]);
    }

    /**
     * Best-effort remote DELETE; local DB row is the caller's responsibility.
     */
    public static function deleteTunnelRemote(Tunnel $tunnel): void
    {
        try {
            (new self())->deleteSite($tunnel->hostname);
        } catch (PanelAlphaException $e) {
            Log::warning('PanelAlpha Online remote delete failed; removing local tunnel row', [
                'hostname' => $tunnel->hostname,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{
     *   path: string,
     *   path_fqdn: string,
     *   wdns_site_id: ?int,
     *   valid_until: ?string,
     *   raw: array<string, mixed>
     * }
     */
    private function parseSuccessBody(Response $response, string $path, string $action): array
    {
        $body = $response->json();
        if (!is_array($body)) {
            throw new PanelAlphaException(
                "PanelAlpha Online {$action} failed: invalid JSON response"
                . ' (HTTP ' . $response->status() . ').'
            );
        }

        if (($body['status'] ?? null) !== 'success') {
            $message = is_string($body['message'] ?? null)
                ? $body['message']
                : (is_string($body['error'] ?? null) ? $body['error'] : "{$action} failed");
            throw new PanelAlphaException(
                "PanelAlpha Online {$action} failed: {$message} (HTTP {$response->status()})."
            );
        }

        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $siteId = $data['model']['id'] ?? $data['id'] ?? null;
        $wdnsSiteId = is_numeric($siteId) ? (int) $siteId : null;
        $returnedPath = isset($data['path']) ? Str::lower((string) $data['path']) : '';
        $pathFqdn = $path . '.' . self::PARENT_DOMAIN;
        if ($returnedPath !== '' && Str::endsWith($returnedPath, '.' . self::PARENT_DOMAIN)) {
            $pathFqdn = $returnedPath;
        } elseif ($returnedPath === $path) {
            $pathFqdn = $path . '.' . self::PARENT_DOMAIN;
        }

        $validUntil = null;
        if (isset($data['valid_until']) && is_string($data['valid_until']) && $data['valid_until'] !== '') {
            $validUntil = $data['valid_until'];
        }

        return [
            'path' => $path,
            'path_fqdn' => $pathFqdn,
            'wdns_site_id' => $wdnsSiteId,
            'valid_until' => $validUntil,
            'raw' => $body,
        ];
    }
}
