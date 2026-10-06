<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\Generation\RoutingSnapshot;
use App\System\Project\Dind\Generation\ZeroDowntimeRedeploy;
use App\System\Project\Dind as DindProject;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Lib\Deploy\DetectAppPort;
use App\Lib\Deploy\Platform\Strategies;
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
        return self::publicUrlOf($this->project->userModel());
    }

    /** The same answer from the model alone, for readers that have no runtime. */
    public static function publicUrlOf(User $user): ?string
    {
        $domain = $user->getMainDomain();
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
        $portDetection = DetectAppPort::detectAllPorts($composePath, $this->project->environment()->forPortDetection());
        $recipePort = self::recipeComposePort($user);
        $primaryPort = $recipePort ?? $portDetection['primary'] ?? 8080;

        $this->project->shell()->logger()?->info(
            "Detected application port: {$primaryPort}" . ($recipePort !== null ? " (the recipe's port:)" : '')
        );

        $this->routeTo($user, $primaryPort);
    }

    /**
     * Points the site at $port: the stored app port, the domain's :80/:443
     * rules, its tunnel ingress and the vhost.
     */
    public function routeTo(User $user, int $primaryPort): void
    {
        // Store primary port in user details (fallback for legacy vhost / bridge)
        $user->setAppPort($primaryPort);
        $user->save();

        // A redeploy of a running app: the site stays where the running
        // version answers until the new version takes traffic.
        $served = RoutingSnapshot::servedPort($user->username);
        if (RoutingSnapshot::defers($user->username)) {
            if (ZeroDowntimeRedeploy::copyHeld($user->username)) {
                $this->project->shell()->logger()?->info(
                    'The site stays on the second copy an earlier redeploy left, until a version answers on its own port'
                );
            } elseif ($served !== null && $served !== $primaryPort) {
                $this->project->shell()->logger()?->info(
                    "The site stays on port {$served}, where the running version answers, until the new version takes traffic"
                );
            }

            return;
        }

        $this->applyRoutes($user, $primaryPort);
    }

    /** The domain's generated rules on $primaryPort, then the vhost rebuilt and the webserver reloaded. */
    public function applyRoutes(User $user, int $primaryPort): void
    {
        $domain = $user->getMainDomain();
        $fqdn = $domain !== null ? trim((string) $domain->domain) : '';
        if ($fqdn === '') {
            Log::warning("Skipping proxy rule create for {$user->username}: no main domain yet");
            return;
        }

        // Update the domain's :80/:443 rules in place, replacing legacy generated rows
        // (*:appPort → localhost); a delete-and-recreate would drop an operator's edits.
        try {
            ProxyRule::syncGeneratedHttpPair($user->username, $fqdn, $primaryPort, $user->getAppPortScheme());
        } catch (\Exception $e) {
            Log::warning("Failed to create proxy rules for {$user->username} {$fqdn}", [
                'error' => $e->getMessage(),
            ]);
        }

        if ($domain !== null && $domain->hasTunnels()) {
            try {
                \App\Integrations\Tunnels\TunnelManager::syncFromProxyRules($user, $domain);
            } catch (\Throwable $e) {
                Log::warning("Failed to sync tunnel ingress after port detect for {$user->username}", [
                    'error' => $e->getMessage(),
                ]);
                // In the deploy log too, in full for our own refusals: an
                // unreadable tunnel token is refused here and needs the operator.
                $this->project->shell()->logger()?->warn('Tunnel ingress was not synced: '
                    . ($e instanceof CloudflareException ? $e->getMessage() : AppHealth::trimReason($e->getMessage())));
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
     * A compose recipe's own `port:` wins over the scan: the scan never routes
     * an image-named datastore, and an app that is one (Qdrant, MinIO) says so
     * in its recipe. The shipped compose manifest declares no port.
     */
    public static function recipeComposePort(User $user): ?int
    {
        $details = $user->getDetails();
        $port = $details['deploy_port'] ?? null;
        if (($details['deploy_strategy'] ?? null) !== Strategies::COMPOSE || !is_int($port)) {
            return null;
        }

        return $port >= 1 && $port <= 65535 ? $port : null;
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
