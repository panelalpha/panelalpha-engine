<?php

namespace App\System\Project\PhpHosting;

use App\Lib\Deploy\Dind\TenantNetwork;
use App\System\Privileged;
use App\System\Project\Dind\TenantNetworkMove as DindTenantNetworkMove;
use App\System\Project\PhpHosting;

/**
 * Moves a running PHP-hosting account to pash-tenants without a restart, as {@see DindTenantNetworkMove} does for DinD:
 * nothing pins an address (sites-db by hostname, mail by host.docker.internal), so it joins, is bound, and leaves.
 */
final class TenantNetworkMove
{
    public function __construct(
        private PhpHosting $project,
    ) {}

    /**
     * The firewall goes on again once the new port exists, which carries nothing until it is bound; the proxy,
     * which caches the account's address for 30s, is reloaded to re-resolve it.
     *
     * @return DindTenantNetworkMove::MOVED|DindTenantNetworkMove::ALREADY|DindTenantNetworkMove::NOT_RUNNING
     */
    public function run(): string
    {
        if (! $this->project->isRunning()) {
            return DindTenantNetworkMove::NOT_RUNNING;
        }

        $account = $this->project->username();
        $system = $this->project->system();
        $networks = $this->networks();

        $system->exec(TenantNetwork::firewallArgv(), [], 60);
        if (! array_key_exists(TenantNetwork::NAME, $networks)) {
            $system->exec(Privileged::docker()->connectNetwork(TenantNetwork::NAME, $account), [], 60);
            $system->exec(TenantNetwork::firewallArgv(), [], 60);
        }

        $this->pointComposeAtTenantNetwork();

        if (! array_key_exists(TenantNetwork::LEGACY_NAME, $networks)) {
            return DindTenantNetworkMove::ALREADY;
        }
        $system->exec(Privileged::docker()->disconnectNetwork(TenantNetwork::LEGACY_NAME, $account), [], 60);

        try {
            $system->reloadWebserver();
        } catch (\Throwable) {
        }

        return DindTenantNetworkMove::MOVED;
    }

    /** @return array<string, mixed> the account container's networks, by name */
    private function networks(): array
    {
        $json = $this->project->system()->exec(TenantNetwork::accountNetworksArgv($this->project->username()), [], 30);
        $networks = json_decode(trim($json), true);

        return is_array($networks) ? $networks : [];
    }

    /** The network's name only, so a recreate keeps the account where it now is. */
    private function pointComposeAtTenantNetwork(): void
    {
        $filesystem = $this->project->system()->filesystem();
        $path = $this->project->composeFilePath();
        if (! $filesystem->fileExists($path)) {
            return;
        }
        $compose = $filesystem->fileGetContents($path);
        $moved = TenantNetwork::composeOnTenantNetwork($compose);
        if ($moved !== $compose) {
            $filesystem->filePutContents($path, $moved);
        }
    }
}
