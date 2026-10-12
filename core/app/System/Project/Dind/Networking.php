<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\Generation\RouteSwitch;
use App\System\Project\Dind\Generation\RoutingSnapshot;
use App\System\Project\Dind\Generation\ZeroDowntimeRedeploy;
use App\System\Project\Dind as DindProject;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Lib\Deploy\DetectAppPort;
use App\Lib\Deploy\Platform\Strategies;
use App\Models\Domain;
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

    /**
     * Route the site to the app's primary port: the recipe's own `port:`, else the one scanned from
     * the inner compose file through the resolver every other reader of it uses.
     */
    public function detectAndCreateProxyRules(User $user): void
    {
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
     * rules, its tunnel ingress and the vhost. Hand-made rules to the previous port follow the app.
     */
    public function routeTo(User $user, int $primaryPort): void
    {
        $previousPort = $user->getAppPort();

        $user->setAppPort($primaryPort);
        $user->save();

        if ($this->staysOnRunningVersion($user, $primaryPort)) {
            return;
        }

        $this->applyRoutes($user, $primaryPort, $previousPort);
    }

    /**
     * A redeploy of a running app keeps the site where the running version answers
     * until the new one takes traffic.
     */
    private function staysOnRunningVersion(User $user, int $primaryPort): bool
    {
        $served = RoutingSnapshot::servedPort($user->username);
        if (!RoutingSnapshot::defers($user->username)) {
            return false;
        }
        if (ZeroDowntimeRedeploy::copyHeld($user->username)) {
            $this->project->shell()->logger()?->info(
                'The site stays on the second copy an earlier redeploy left, until a version answers on its own port'
            );
        } elseif ($served !== null && $served !== $primaryPort) {
            $this->project->shell()->logger()?->info(
                "The site stays on port {$served}, where the running version answers, until the new version takes traffic"
            );
        }

        return true;
    }

    /** The domain's generated rules on $primaryPort, then the vhost rebuilt and the webserver reloaded. */
    public function applyRoutes(User $user, int $primaryPort, ?int $fromPort = null): void
    {
        if ($fromPort !== null && $fromPort !== $primaryPort) {
            $this->moveHandMadeRules($user, $fromPort, $primaryPort);
        }

        $domain = $user->getMainDomain();
        $fqdn = $domain !== null ? trim((string) $domain->domain) : '';
        if ($fqdn === '') {
            Log::warning("Skipping proxy rule create for {$user->username}: no main domain yet");
            return;
        }

        $this->syncGeneratedHttpRules($user, $fqdn, $primaryPort);
        if ($domain !== null && $domain->hasTunnels()) {
            $this->syncTunnelIngress($user, $domain);
        }
        $this->rebuildVhost($user, $domain);
    }

    /**
     * An app that was not running took no routing snapshot, so its hand-made rules move here
     * ({@see RoutingSnapshot::apply()} otherwise).
     */
    private function moveHandMadeRules(User $user, int $fromPort, int $primaryPort): void
    {
        try {
            (new RouteSwitch($this->project->system(), $user->username))->follow($fromPort, $primaryPort);
        } catch (\Throwable $e) {
            Log::warning("Failed to move the proxy rules of {$user->username} to port {$primaryPort}: " . $e->getMessage());
        }
    }

    /** The domain's :80/:443 rules updated in place: a delete-and-recreate would drop an operator's edits. */
    private function syncGeneratedHttpRules(User $user, string $fqdn, int $primaryPort): void
    {
        try {
            ProxyRule::syncGeneratedHttpPair($user->username, $fqdn, $primaryPort, $user->getAppPortScheme());
        } catch (\Exception $e) {
            Log::warning("Failed to create proxy rules for {$user->username} {$fqdn}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A failure goes to the deploy log too, in full for our own refusals: an unreadable
     * tunnel token is refused here and needs the operator.
     */
    private function syncTunnelIngress(User $user, Domain $domain): void
    {
        try {
            \App\Integrations\Tunnels\TunnelManager::syncFromProxyRules($user, $domain);
        } catch (\Throwable $e) {
            Log::warning("Failed to sync tunnel ingress after port detect for {$user->username}", [
                'error' => $e->getMessage(),
            ]);
            $this->project->shell()->logger()?->warn('Tunnel ingress was not synced: '
                . ($e instanceof CloudflareException ? $e->getMessage() : AppHealth::trimReason($e->getMessage())));
        }
    }

    /** A failure goes to the deploy log too, so a later "no vhost is loaded" traces back here. */
    private function rebuildVhost(User $user, ?Domain $domain): void
    {
        try {
            $this->project->system()->webserver()->rebuildDomainConfig($domain);
            $this->project->system()->webserver()->reload(false);
        } catch (\Exception $e) {
            Log::warning("Failed to rebuild domain vhost after proxy rules for {$user->username}", [
                'error' => $e->getMessage(),
            ]);
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
}
