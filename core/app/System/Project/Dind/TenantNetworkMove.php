<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Dind\TenantNetwork;
use App\System\Project\Dind as DindProject;
use RuntimeException;

/**
 * Moves a running account from pash-default-network to pash-tenants
 * without restarting it.
 *
 * The app goes with it: its compose file pins the database name to sites-db's
 * address on the old network, which the account cannot reach from the new one.
 * The account joins the new network first, the app is re-pinned and recreated
 * while both are attached, and only then does the account leave the old one --
 * so the app is without its database only for its own recreate. A failure
 * before that point leaves the account on both, working as it did.
 */
final class TenantNetworkMove
{
    public const MOVED = 'moved';

    public const ALREADY = 'already';

    public const NOT_RUNNING = 'not-running';

    public function __construct(
        private DindProject $dind,
    ) {
    }

    /** @return self::MOVED|self::ALREADY|self::NOT_RUNNING */
    public function run(): string
    {
        // A stopped account would start on the new network with the old pin;
        // left alone, it starts where it was and still works.
        if (!$this->dind->isRunning()) {
            return self::NOT_RUNNING;
        }

        $account = $this->dind->username();
        $system = $this->dind->system();
        $networks = $this->networks();
        $onLegacy = array_key_exists(TenantNetwork::LEGACY_NAME, $networks);

        $system->exec(TenantNetwork::firewallArgv(), [], 60);
        if (!array_key_exists(TenantNetwork::NAME, $networks)) {
            $system->exec(['sudo', 'docker', 'network', 'connect', TenantNetwork::NAME, $account], [], 60);
            // The new port carries nothing until it is bound.
            $system->exec(TenantNetwork::firewallArgv(), [], 60);
        }

        $address = TenantNetwork::sitesDbAddressFor(
            $system->exec(TenantNetwork::accountNetworksArgv($account), [], 30)
        );
        if ($address === null) {
            throw new RuntimeException("{$account} joined " . TenantNetwork::NAME . ' but has no address on it');
        }

        if ($this->repin($address)) {
            $up = $this->dind->projectAction('up');
            if ($up['exit_code'] !== 0) {
                throw new RuntimeException(
                    "{$account}: the app did not come back up on its new database address: " . trim($up['stderr'])
                );
            }
        }

        $this->pointComposeAtTenantNetwork();

        if (!$onLegacy) {
            return self::ALREADY;
        }
        $system->exec(['sudo', 'docker', 'network', 'disconnect', TenantNetwork::LEGACY_NAME, $account], [], 60);

        // The proxy caches the account's address for 30s and kept sending to
        // the old one: measured, the site timed out for 23s after the move. A
        // reload re-resolves at once. A failed one only means waiting the 30s.
        try {
            $system->reloadWebserver();
        } catch (\Throwable) {
        }

        return self::MOVED;
    }

    /** @return array<string, mixed> the account container's networks, by name */
    private function networks(): array
    {
        $json = $this->dind->system()->exec(TenantNetwork::accountNetworksArgv($this->dind->username()), [], 30);
        $networks = json_decode(trim($json), true);

        return is_array($networks) ? $networks : [];
    }

    /**
     * The account's compose file names the new network, so a recreate keeps it
     * there. That one line only: the running container was made from this file
     * and the files beside it. Re-rendering the template under it replaced files
     * an older account mounts (supervisord.conf), and that account could not
     * start again at the next Docker restart.
     */
    private function pointComposeAtTenantNetwork(): void
    {
        $filesystem = $this->dind->system()->filesystem();
        $path = $this->dind->composeFilePath();
        if (!$filesystem->fileExists($path)) {
            return;
        }
        $compose = $filesystem->fileGetContents($path);
        $moved = TenantNetwork::composeOnTenantNetwork($compose);
        if ($moved !== $compose) {
            $filesystem->filePutContents($path, $moved);
        }
    }

    /** Whether any of the app's compose files pinned the database elsewhere. */
    private function repin(string $address): bool
    {
        $filesystem = $this->dind->system()->filesystem();
        $chown = $this->dind->userModel()->getChownString();
        $changed = false;

        $files = explode(':', $this->dind->userAppComposeEnv()['COMPOSE_FILE']);
        foreach (array_filter($files, static fn (string $f): bool => $f !== '') as $file) {
            if (!$filesystem->fileExists($file)) {
                continue;
            }
            $yaml = $filesystem->fileGetContents($file);
            $repinned = AppDatabase::repinned($yaml, $address);
            if ($repinned !== $yaml) {
                // The run file inlines env_vars and passwords; the overrides are 0644 wherever written.
                $mode = basename($file) === EngineArtifacts::RUN_COMPOSE ? EngineArtifacts::RUN_COMPOSE_MODE : '644';
                $filesystem->filePutContents($file, $repinned, $chown, $mode);
                $changed = true;
            }
        }

        return $changed;
    }
}
