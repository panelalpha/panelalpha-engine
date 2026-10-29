<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Integrations\Tunnels\PanelAlphaConnect;
use App\Integrations\Tunnels\TunnelManager;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Lib\Apis\PanelAlpha\PanelAlphaException;
use App\Lib\Domains\DomainAllocator;
use App\Lib\Domains\DomainPlan;
use App\Models\Domain;
use App\Models\Tunnel;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A panelalpha.online name that shows an imported site before its own domain
 * points here: named after the domain (`wp.com` → `wp-com`), forwarded with
 * the domain as Host, and never expiring on its own. The DNS check removes it
 * once the domain answers on this engine.
 */
final class PreviewTunnel
{
    private const ATTEMPTS = 5;

    private const MAX_LABEL_LENGTH = 63;

    /**
     * @param  (Closure(User, Domain, string): Tunnel)|null  $create
     * @param  (Closure(User, Tunnel): void)|null  $remove
     */
    public function __construct(
        private ?Closure $create = null,
        private ?Closure $remove = null,
    ) {
        $this->create ??= static fn (User $user, Domain $domain, string $hostname): Tunnel => TunnelManager::createTunnel(
            $user,
            $domain,
            $hostname,
            Tunnel::PROVIDER_PANELALPHA,
            Tunnel::PURPOSE_PREVIEW,
        );
        $this->remove ??= TunnelManager::removeTunnel(...);
    }

    /**
     * The preview hostname, or null without Connect or when Connect refused:
     * the import goes on either way, the site answers once DNS points here.
     */
    public function open(Domain $domain): ?string
    {
        $user = $domain->user;
        if (! $user instanceof User || ! PanelAlphaConnect::available()) {
            return null;
        }

        try {
            return $this->createUnderAFreeLabel($user, $domain, self::label((string) $domain->domain));
        } catch (Throwable $e) {
            Log::warning("No preview tunnel for {$domain->domain}: ".$e->getMessage());

            return null;
        }
    }

    /** Remove the preview, if it is still there. */
    public function close(string $hostname): void
    {
        $tunnel = Tunnel::findByHostname($hostname);
        $user = $tunnel?->user;
        if ($tunnel === null || ! $user instanceof User) {
            return;
        }

        ($this->remove)($user, $tunnel);
    }

    /** The domain as one panelalpha.online label: dots become dashes. */
    public static function label(string $domain): string
    {
        $label = (string) preg_replace('/[^a-z0-9]+/', '-', strtolower($domain));

        return trim(substr(trim($label, '-'), 0, self::MAX_LABEL_LENGTH), '-');
    }

    private function createUnderAFreeLabel(User $user, Domain $domain, string $label): string
    {
        $candidate = $label;
        for ($attempt = 1; ; $attempt++) {
            $hostname = $candidate.'.'.PanelAlphaConnect::PARENT_DOMAIN;
            try {
                return ($this->create)($user, $domain, $hostname)->hostname;
            } catch (CloudflareException|PanelAlphaException $e) {
                if ($attempt >= self::ATTEMPTS || ! DomainAllocator::nameIsTaken($e->getMessage())) {
                    throw $e;
                }
            }
            $candidate = DomainPlan::suffixed($label);
        }
    }
}
