<?php

namespace App\Integrations\Tunnels;

use App\Lib\Apis\Cloudflare as CloudflareApi;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\System;
use App\System\Project\Dind;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Tunnel orchestration for DinD projects.
 *
 * Token, remote cfd_tunnel, DNS CNAME, ingress from ProxyRules, and cloudflared
 * under supervisord. Public hostname rows live in `tunnels`; PanelAlpha Online
 * is {@see PanelAlphaHub}, dispatched by {@see TunnelManager}.
 */
class Cloudflare
{
    public static function tunnelName(string $username): string
    {
        return 'panelalpha-' . $username;
    }

    public static function clientFor(User $user): CloudflareApi
    {
        $token = $user->getCloudflareApiToken();
        if ($token === null) {
            throw new CloudflareException(
                "Project '{$user->username}' has no Cloudflare API token. "
                . 'Run: pae projects:settings:set --project=' . $user->username
                . ' cloudflare-api-token TOKEN'
            );
        }

        return new CloudflareApi($token);
    }

    /**
     * Validate token, cache account id, persist encrypted token.
     *
     * @return array{account_id: string, account_name: string}
     */
    public static function setApiToken(User $user, string $apiToken): array
    {
        $apiToken = trim($apiToken);
        if ($apiToken === '') {
            throw new CloudflareException('Cloudflare API token must not be empty.');
        }

        $client = new CloudflareApi($apiToken);
        $account = $client->resolveAccount();

        $user->setDetails([
            'cloudflare_api_token' => $apiToken,
            'cloudflare_account_id' => $account['id'],
        ]);
        $user->save();

        return [
            'account_id' => $account['id'],
            'account_name' => $account['name'],
        ];
    }

    /**
     * Ensure a remotely-managed tunnel exists and cloudflared is running in DinD.
     *
     * @return array{tunnel_id: string, tunnel_token: string, account_id: string}
     */
    public static function ensureTunnel(User $user): array
    {
        $client = self::clientFor($user);
        $accountId = $user->getCloudflareAccountId();
        if ($accountId === null) {
            $account = $client->resolveAccount();
            $accountId = $account['id'];
            $user->setDetails(['cloudflare_account_id' => $accountId]);
            $user->save();
        }

        $name = self::tunnelName($user->username);
        $tunnelId = $user->getCloudflareTunnelId();
        $tunnelToken = $user->getCloudflareTunnelToken();

        if ($tunnelId === null || $tunnelToken === null) {
            $existing = $client->findTunnelByName($accountId, $name);
            if ($existing !== null) {
                $tunnelId = $existing['id'];
                $tunnelToken = $existing['token'];
            } else {
                $created = $client->createTunnel($accountId, $name);
                $tunnelId = $created['id'];
                $tunnelToken = $created['token'];
            }
            $user->setDetails([
                'cloudflare_tunnel_id' => $tunnelId,
                'cloudflare_tunnel_token' => $tunnelToken,
                'cloudflare_account_id' => $accountId,
            ]);
            $user->save();
        }

        self::ensureConnectorRunning($user);

        return [
            'tunnel_id' => $tunnelId,
            'tunnel_token' => $tunnelToken,
            'account_id' => $accountId,
        ];
    }

    /**
     * Attach a Cloudflare public hostname to an existing local domain.
     * Caller must have already run {@see TunnelManager::assertCreatable}.
     */
    public static function createHostname(User $user, Domain $domain, string $hostname): Tunnel
    {
        self::assertDinD($user);

        $client = self::clientFor($user);
        $cfTunnel = self::ensureTunnel($user);
        $zone = $client->findZoneForHostname($hostname);
        $record = $client->ensureDnsCname($zone['id'], $hostname, $cfTunnel['tunnel_id']);

        $tunnel = Tunnel::create([
            'domain_id' => $domain->id,
            'user_id' => $user->id,
            'provider' => Tunnel::PROVIDER_CLOUDFLARE,
            'hostname' => $hostname,
            'details' => [
                'cloudflare_zone_id' => $zone['id'],
                'cloudflare_dns_record_id' => $record['id'],
            ],
        ]);

        self::syncFromProxyRules($user, $domain);

        return $tunnel;
    }

    /**
     * Remove Cloudflare DNS + ingress for one hostname row (does not delete the DB row).
     */
    public static function teardownHostname(User $user, Tunnel $tunnel): void
    {
        $token = $user->getCloudflareApiToken();
        $accountId = $user->getCloudflareAccountId();
        $tunnelId = $user->getCloudflareTunnelId();
        if ($token === null || $accountId === null || $tunnelId === null) {
            return;
        }

        try {
            $client = new CloudflareApi($token);
            $details = $tunnel->getDetails();
            $zoneId = is_string($details['cloudflare_zone_id'] ?? null) ? $details['cloudflare_zone_id'] : null;
            $recordId = is_string($details['cloudflare_dns_record_id'] ?? null) ? $details['cloudflare_dns_record_id'] : null;

            if ($zoneId && $recordId) {
                $client->deleteDnsRecord($zoneId, $recordId);
            } elseif ($zoneId) {
                $existing = $client->findDnsRecord($zoneId, $tunnel->hostname);
                if ($existing !== null) {
                    $client->deleteDnsRecord($zoneId, $existing['id']);
                }
            }

            $ingress = $client->getIngress($accountId, $tunnelId);
            $updated = CloudflareApi::removeHostnameIngress($ingress, $tunnel->hostname);
            $client->putIngress($accountId, $tunnelId, $updated);
        } catch (\Throwable $e) {
            Log::warning(
                "Cloudflare tunnel row teardown failed for {$tunnel->hostname}: " . $e->getMessage()
            );
        }
    }

    /**
     * Push Cloudflare Tunnel ingress from ProxyRule rows for this domain (source of truth).
     * Empty/disabled rules remove each tunnel hostname from ingress (DNS stays).
     */
    public static function syncFromProxyRules(User $user, Domain $domain): void
    {
        $tunnels = Tunnel::forDomain($domain);
        $cloudflare = array_values(array_filter(
            $tunnels,
            static fn (Tunnel $t): bool => $t->isCloudflare()
        ));
        if ($cloudflare === []) {
            return;
        }

        self::assertDinD($user);
        $client = self::clientFor($user);
        $cfTunnel = self::ensureTunnel($user);

        $rules = ProxyRule::query()
            ->where('transport', 'http')
            ->where('enabled', true)
            ->where('server_name', $domain->domain)
            ->orderBy('listen_port')
            ->get();

        $ingress = $client->getIngress($cfTunnel['account_id'], $cfTunnel['tunnel_id']);

        if ($rules->isEmpty()) {
            foreach ($cloudflare as $tunnel) {
                $ingress = CloudflareApi::removeHostnameIngress($ingress, $tunnel->hostname);
            }
            $client->putIngress($cfTunnel['account_id'], $cfTunnel['tunnel_id'], $ingress);
            return;
        }

        /** @var ProxyRule $rule */
        $rule = $rules->firstWhere('listen_port', 443)
            ?? $rules->firstWhere('listen_port', 80)
            ?? $rules->first();

        $upstreamPort = (int) $rule->upstream_port;
        if ($upstreamPort < 1 || $upstreamPort > 65535) {
            throw new CloudflareException('Upstream port must be 1-65535.');
        }

        $passwordOn = \App\System\Project\SitePasswordProtection::isEnabled($user);
        if ($passwordOn) {
            // Hairpin through host nginx-proxy so the site password gate applies.
            // DinD compose already maps host.docker.internal → host-gateway.
            $sslOn = $domain->sslEnabled();
            $origin = $sslOn
                ? 'https://host.docker.internal:443'
                : 'http://host.docker.internal:80';
            $originRequest = [
                'httpHostHeader' => $domain->domain,
            ];
            if ($sslOn) {
                $originRequest['noTLSVerify'] = true;
            }
        } else {
            $origin = CloudflareApi::originServiceUrl($upstreamPort);
            $originRequest = new \stdClass();
        }

        foreach ($cloudflare as $tunnel) {
            $ingress = CloudflareApi::upsertHostnameIngress(
                $ingress,
                $tunnel->hostname,
                $origin,
                $originRequest
            );

            $details = $tunnel->getDetails();
            $zoneId = is_string($details['cloudflare_zone_id'] ?? null) ? $details['cloudflare_zone_id'] : null;
            if ($zoneId === null || $zoneId === '') {
                $zone = $client->findZoneForHostname($tunnel->hostname);
                $zoneId = $zone['id'];
            }
            $record = $client->ensureDnsCname($zoneId, $tunnel->hostname, $cfTunnel['tunnel_id']);
            $tunnel->setDetails([
                'cloudflare_zone_id' => $zoneId,
                'cloudflare_dns_record_id' => $record['id'],
            ]);
            $tunnel->save();
        }

        $client->putIngress($cfTunnel['account_id'], $cfTunnel['tunnel_id'], $ingress);
    }

    /**
     * Tear down Cloudflare tunnels + connector for a project.
     * PanelAlpha Online rows are removed via domain/account delete
     * ({@see TunnelManager::deleteTunnel} → remote DELETE on the license proxy).
     */
    public static function teardownProject(User $user): void
    {
        $token = $user->getCloudflareApiToken();
        $accountId = $user->getCloudflareAccountId();
        $tunnelId = $user->getCloudflareTunnelId();

        $tunnels = Tunnel::query()
            ->where('user_id', $user->id)
            ->where('provider', Tunnel::PROVIDER_CLOUDFLARE)
            ->get();
        foreach ($tunnels as $tunnel) {
            try {
                self::teardownHostname($user, $tunnel);
                $tunnel->delete();
            } catch (\Throwable $e) {
                Log::warning(
                    "Cloudflare tunnel teardown failed for {$tunnel->hostname}: " . $e->getMessage()
                );
                try {
                    $tunnel->delete();
                } catch (\Throwable $ignored) {
                    // best-effort
                }
            }
        }

        if ($token !== null && $accountId !== null && $tunnelId !== null) {
            try {
                $client = new CloudflareApi($token);
                $client->deleteTunnel($accountId, $tunnelId);
            } catch (\Throwable $e) {
                Log::warning(
                    "Cloudflare tunnel teardown failed for {$user->username}: " . $e->getMessage()
                );
            }
        }

        self::clearConnectorState($user);
    }

    /**
     * Clear connector conf (autostart=false) + wipe CF ids from User.details.
     * Does not delete the remote CF tunnel object (caller may have already done that).
     */
    public static function clearConnectorState(User $user): void
    {
        try {
            self::disableConnector($user);
        } catch (\Throwable $e) {
            Log::warning(
                "Could not stop cloudflared for {$user->username}: " . $e->getMessage()
            );
        }

        // Legacy path from entrypoint-runner era.
        self::removeConnectorToken($user);

        $details = $user->getDetails();
        unset(
            $details['cloudflare_api_token'],
            $details['cloudflare_tunnel_token'],
            $details['cloudflare_tunnel_id'],
            $details['cloudflare_account_id']
        );
        $user->details = $details;
        $user->save();
    }

    /**
     * @deprecated Legacy file used by entrypoint-runner cloudflared.sh; kept for cleanup.
     */
    public static function connectorEnvPath(User $user): string
    {
        return $user->project()->homeDirPath() . '/.panelalpha/cloudflared.env';
    }

    /**
     * @deprecated Prefer renderCloudflaredSupervisorConf with the tunnel token.
     */
    public static function writeConnectorToken(User $user, string $tunnelToken): void
    {
        self::renderCloudflaredSupervisorConf($user, true, $tunnelToken);
    }

    public static function removeConnectorToken(User $user): void
    {
        try {
            $path = self::connectorEnvPath($user);
            (new System())->exec(['sudo', 'rm', '-f', $path]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    public static function cloudflaredSupervisorConfPath(User $user): string
    {
        return $user->project()->projectDirPath() . '/supervisord.conf.d/cloudflared.conf';
    }

    public static function cloudflaredSupervisorConfTemplatePath(): string
    {
        return (new System())->projectFilesTemplateDirPath('dind')
            . '/supervisord.conf.d/cloudflared.conf.blade.php';
    }

    /**
     * Write supervisord program conf for cloudflared (autostart + optional TUNNEL_TOKEN).
     */
    public static function renderCloudflaredSupervisorConf(
        User $user,
        bool $autostart,
        ?string $tunnelToken = null
    ): void {
        $project = $user->project();
        $system = $project->system();
        $dir = $project->projectDirPath() . '/supervisord.conf.d';
        $system->runProcess(['sudo', 'mkdir', '-p', $dir]);

        $token = $autostart ? (is_string($tunnelToken) ? trim($tunnelToken) : '') : '';
        if ($autostart && $token === '') {
            $token = trim((string) ($user->getCloudflareTunnelToken() ?? ''));
        }

        $system->filesystem()->makeFileFromTemplate(
            self::cloudflaredSupervisorConfPath($user),
            self::cloudflaredSupervisorConfTemplatePath(),
            [
                'autostart' => $autostart && $token !== '',
                'tunnelToken' => $token !== '' ? $token : null,
            ],
            null,
            '600'
        );
    }

    /**
     * Apply cloudflared conf changes inside a running DinD account (best-effort).
     */
    public static function reloadCloudflaredSupervisor(User $user): void
    {
        $runtime = $user->project()->runtime();
        if (!($runtime instanceof Dind)) {
            return;
        }
        try {
            $runtime->shell()->runProcess(
                ['supervisorctl', '-c', '/etc/supervisor/supervisord.conf', 'reread'],
                [],
                30
            );
            $runtime->shell()->runProcess(
                ['supervisorctl', '-c', '/etc/supervisor/supervisord.conf', 'update', 'cloudflared'],
                [],
                60
            );
        } catch (\Throwable $e) {
            // Container may be down during account create / teardown.
            Log::info(
                "supervisorctl update cloudflared skipped for {$user->username}: " . $e->getMessage()
            );
        }
    }

    public static function ensureConnectorRunning(User $user): void
    {
        self::assertDinD($user);

        $tunnelToken = $user->getCloudflareTunnelToken();
        if ($tunnelToken === null || trim($tunnelToken) === '') {
            throw new CloudflareException(
                "Project '{$user->username}' has no Cloudflare tunnel token; call ensureTunnel first."
            );
        }

        self::renderCloudflaredSupervisorConf($user, true, $tunnelToken);
        self::removeConnectorToken($user);
        self::reloadCloudflaredSupervisor($user);
    }

    /**
     * Stop cloudflared and clear token from supervisord conf (keep User.details tunnel ids).
     */
    public static function disableConnector(User $user): void
    {
        if (!($user->project()->runtime() instanceof Dind)) {
            return;
        }
        self::renderCloudflaredSupervisorConf($user, false);
        self::removeConnectorToken($user);
        self::reloadCloudflaredSupervisor($user);
    }

    public static function stopConnector(User $user): void
    {
        self::disableConnector($user);
    }

    public static function assertDinD(User $user): void
    {
        if (!($user->project()->runtime() instanceof Dind)) {
            throw new CloudflareException(
                "Cloudflare tunnels are only supported for DinD projects ('{$user->username}' is not DinD)."
            );
        }
    }
}
