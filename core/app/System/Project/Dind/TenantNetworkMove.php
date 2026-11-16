<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Dind\TenantNetwork;
use App\System as EngineSystem;
use App\System\Privileged;
use App\System\Project\Dind as DindProject;
use RuntimeException;

/**
 * Moves a running account from pash-default-network to pash-tenants without a restart: join, re-pin the app's
 * sites-db address and recreate it while on both, then leave, so a failure leaves it working on both.
 */
final class TenantNetworkMove
{
    public const MOVED = 'moved';

    public const ALREADY = 'already';

    public const NOT_RUNNING = 'not-running';

    public function __construct(
        private DindProject $dind,
    ) {}

    /**
     * A stopped account is left alone: started on the new network with the old pin it would not work.
     *
     * @return self::MOVED|self::ALREADY|self::NOT_RUNNING
     */
    public function run(): string
    {
        if (! $this->dind->isRunning()) {
            return self::NOT_RUNNING;
        }

        $account = $this->dind->username();
        $system = $this->dind->system();
        $networks = $this->networks();
        $onLegacy = array_key_exists(TenantNetwork::LEGACY_NAME, $networks);

        $this->joinTenantNetwork($system, $account, $networks);
        $this->moveAppToTenantAddress($system, $account);

        $this->pointComposeAtTenantNetwork();

        if (! $onLegacy) {
            return self::ALREADY;
        }
        $system->exec(Privileged::docker()->disconnectNetwork(TenantNetwork::LEGACY_NAME, $account), [], 60);

        $this->reloadProxy($system);

        return self::MOVED;
    }

    /**
     * The firewall goes on again once the account joined: the new port carries nothing until it is bound.
     *
     * @param  array<string, mixed>  $networks
     */
    private function joinTenantNetwork(EngineSystem $system, string $account, array $networks): void
    {
        $system->exec(TenantNetwork::firewallArgv(), [], 60);
        if (! array_key_exists(TenantNetwork::NAME, $networks)) {
            $system->exec(Privileged::docker()->connectNetwork(TenantNetwork::NAME, $account), [], 60);
            $system->exec(TenantNetwork::firewallArgv(), [], 60);
        }
    }

    private function moveAppToTenantAddress(EngineSystem $system, string $account): void
    {
        $address = TenantNetwork::sitesDbAddressFor(
            $system->exec(TenantNetwork::accountNetworksArgv($account), [], 30)
        );
        if ($address === null) {
            throw new RuntimeException("{$account} joined ".TenantNetwork::NAME.' but has no address on it');
        }

        if ($this->repin($address)) {
            $up = $this->dind->projectAction('up');
            if ($up['exit_code'] !== 0) {
                throw new RuntimeException(
                    "{$account}: the app did not come back up on its new database address: ".trim($up['stderr'])
                );
            }
        }
    }

    /**
     * The proxy caches the account's address for 30s (the site timed out for 23s after a move); a reload
     * re-resolves at once, and a failed one only means waiting those 30s.
     */
    private function reloadProxy(EngineSystem $system): void
    {
        try {
            $system->reloadWebserver();
        } catch (\Throwable) {
        }
    }

    /** @return array<string, mixed> the account container's networks, by name */
    private function networks(): array
    {
        $json = $this->dind->system()->exec(TenantNetwork::accountNetworksArgv($this->dind->username()), [], 30);
        $networks = json_decode(trim($json), true);

        return is_array($networks) ? $networks : [];
    }

    /**
     * The account's compose file names the new network, that one line only: re-rendering the template replaced
     * files an older account mounts (supervisord.conf), and it could not start again after a Docker restart.
     */
    private function pointComposeAtTenantNetwork(): void
    {
        $filesystem = $this->dind->system()->filesystem();
        $path = $this->dind->composeFilePath();
        if (! $filesystem->fileExists($path)) {
            return;
        }
        $compose = $filesystem->fileGetContents($path);
        $moved = TenantNetwork::composeOnTenantNetwork($compose);
        if ($moved !== $compose) {
            $filesystem->filePutContents($path, $moved);
        }
    }

    /**
     * Whether any of the app's compose files pinned the database elsewhere. The run file inlines env_vars and
     * passwords, so it keeps its owner-only mode; the overrides are 0644 wherever written.
     */
    private function repin(string $address): bool
    {
        $filesystem = $this->dind->system()->filesystem();
        $chown = $this->dind->userModel()->getChownString();
        $changed = false;

        foreach ($this->dind->userAppComposeFiles() as $file) {
            if (! $filesystem->fileExists($file)) {
                continue;
            }
            $filesystem->assertNotSymlink($file);
            $yaml = $filesystem->fileGetContents($file);
            $repinned = AppDatabase::repinned($yaml, $address);
            if ($repinned !== $yaml) {
                $mode = basename($file) === EngineArtifacts::RUN_COMPOSE ? EngineArtifacts::RUN_COMPOSE_MODE : '644';
                $filesystem->writeFileReplacingPath($file, $repinned, $chown, $mode);
                $changed = true;
            }
        }

        return $changed;
    }
}
