<?php

namespace App\System\Project\PhpHosting;

use App\Lib\Deploy\Dind\TenantNetwork;
use App\System\Project\Dind\TenantNetworkMove as DindTenantNetworkMove;
use App\System\Project\PhpHosting;

/**
 * Moves a running PHP-hosting account from pash-default-network to
 * pash-tenants (engine#217) without restarting it, as
 * {@see DindTenantNetworkMove} does for DinD accounts.
 *
 * Nothing in the account pins an address: the database is reached by
 * sites-db's hostname, which Docker's DNS answers on either network, and mail
 * by host.docker.internal. So it joins, is bound, and leaves the old network.
 */
final class TenantNetworkMove
{
    public function __construct(
        private PhpHosting $project,
    ) {
    }

    /** @return DindTenantNetworkMove::MOVED|DindTenantNetworkMove::ALREADY|DindTenantNetworkMove::NOT_RUNNING */
    public function run(): string
    {
        if (!$this->project->isRunning()) {
            return DindTenantNetworkMove::NOT_RUNNING;
        }

        $account = $this->project->username();
        $system = $this->project->system();
        $networks = $this->networks();

        $system->exec(TenantNetwork::firewallArgv(), [], 60);
        if (!array_key_exists(TenantNetwork::NAME, $networks)) {
            $system->exec(['sudo', 'docker', 'network', 'connect', TenantNetwork::NAME, $account], [], 60);
            // The new port carries nothing until it is bound (engine#529).
            $system->exec(TenantNetwork::firewallArgv(), [], 60);
        }

        $this->pointComposeAtTenantNetwork();

        if (!array_key_exists(TenantNetwork::LEGACY_NAME, $networks)) {
            return DindTenantNetworkMove::ALREADY;
        }
        $system->exec(['sudo', 'docker', 'network', 'disconnect', TenantNetwork::LEGACY_NAME, $account], [], 60);

        // The proxy caches the account's address for 30s; a reload re-resolves it.
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
        if (!$filesystem->fileExists($path)) {
            return;
        }
        $compose = $filesystem->fileGetContents($path);
        $moved = TenantNetwork::composeOnTenantNetwork($compose);
        if ($moved !== $compose) {
            $filesystem->filePutContents($path, $moved);
        }
    }
}
