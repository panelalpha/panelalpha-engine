<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind as DindProject;
use App\Lib\Deploy\DetectAppPort;
use App\Models\ProxyRule;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The public URL a deployed app is reachable at, and the reverse-proxy rules
 * that make it so.
 */
class Networking
{
    private DindProject $project;

    public function __construct(DindProject $project)
    {
        $this->project = $project;
    }

    public function publicAppUrl(): ?string
    {
        $domain = $this->project->userModel()->getMainDomain();
        if ($domain === null) {
            return null;
        }
        $name = trim((string) $domain->domain);
        if ($name === '') {
            return null;
        }
        $details = is_array($domain->details) ? $domain->details : [];
        $scheme = !empty($details['ssl_disabled']) ? 'http' : 'https';

        return $scheme . '://' . $name;
    }

    public function detectAndCreateProxyRules(User $user): void
    {
        // Detect all public ports from the user's compose file, via the same
        // resolver every other reader of the inner compose file goes through.
        $composePath = $this->project->userAppComposeFileForPorts();
        $portDetection = DetectAppPort::detectAllPorts($composePath);
        $primaryPort = $portDetection['primary'] ?? 8080;

        $this->project->shell()->logger()?->info("Detected application port: {$primaryPort}");

        // Store primary port in user details (fallback for legacy vhost / bridge)
        $user->setAppPort($primaryPort);
        $user->save();

        $domain = $user->getMainDomain();
        $fqdn = $domain !== null ? trim((string) $domain->domain) : '';
        if ($fqdn === '') {
            Log::warning("Skipping proxy rule create for {$user->username}: no main domain yet");
            return;
        }

        // Replace legacy generated rows (*:appPort → localhost) with domain :80/:443 rules.
        ProxyRule::forUser($user->username)->where('is_generated', true)->delete();

        $this->createDomainProxyRule($user->username, $fqdn, 80, $primaryPort, true);
        $this->createDomainProxyRule($user->username, $fqdn, 443, $primaryPort, true);

        if ($domain !== null && $domain->hasTunnels()) {
            try {
                \App\Integrations\Tunnels\TunnelManager::syncFromProxyRules($user, $domain);
            } catch (\Throwable $e) {
                Log::warning("Failed to sync tunnel ingress after port detect for {$user->username}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $this->project->system()->webserver()->rebuildDomainConfig($domain);
            $this->project->system()->webserver()->reload(false);
        } catch (\Exception $e) {
            Log::warning("Failed to rebuild domain vhost after proxy rules for {$user->username}", [
                'error' => $e->getMessage(),
            ]);
            // In the deploy log too, so a later "no vhost is loaded" traces back here.
            $this->project->shell()->logger()?->warn(
                'Webserver reload after proxy rules failed: ' . AppHealth::trimReason($e->getMessage())
            );
        }
    }

    /**
     * Public HTTP listen on the domain (80/443 or an extra port) → account container.
     */
    public function createDomainProxyRule(
        string $username,
        string $fqdn,
        int $listenPort,
        int $upstreamPort,
        bool $isPrimary = false
    ): void {
        try {
            ProxyRule::upsertGeneratedHttpRule($username, $fqdn, $listenPort, $upstreamPort, $isPrimary);
        } catch (\Exception $e) {
            Log::warning("Failed to create proxy rule for {$username} {$fqdn}:{$listenPort}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @deprecated Use createDomainProxyRule(); kept for callers that still pass an app listen port.
     */
    public function createProxyRuleForPort(string $username, int $port, bool $isPrimary = false): void
    {
        $domain = $this->project->userModel()->getMainDomain();
        $fqdn = $domain !== null ? trim((string) $domain->domain) : '';
        if ($fqdn === '') {
            return;
        }
        $this->createDomainProxyRule($username, $fqdn, 80, $port, $isPrimary);
        $this->createDomainProxyRule($username, $fqdn, 443, $port, $isPrimary);
    }
}
