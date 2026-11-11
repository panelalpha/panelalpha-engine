<?php

namespace App\Lib\Deploy\Dind;

use App\Lib\Deploy\Engine\AccountStorage;
use App\Lib\Deploy\Engine\EngineAccount;

/**
 * The nested daemon's data-root, `~/docker` in the account's home, so a full store fills the tenant's quota,
 * not the host. The free-space probe runs inside the account; deletion removes the tree explicitly.
 */
final class DindAccountStorage implements AccountStorage
{
    /**
     * A shell expression, not a literal: sysbox gives the account container its
     * username as hostname, so this resolves per account untemplated.
     */
    public function dataRoot(): string
    {
        return '/home/$(hostname)/docker';
    }

    /**
     * @return list<string>
     */
    public function freeSpaceProbeArgv(): array
    {
        return ['sh', '-lc', 'df -Pk "'.$this->dataRoot().'" 2>/dev/null || true'];
    }

    public function parseFreeBytes(string $output): ?int
    {
        return DindBuildStorage::parseDfAvailableBytes($output);
    }

    public function fullWipeScript(): string
    {
        return DindBuildStorage::fullWipeScript();
    }

    /**
     * Stop the nested Docker daemon. The caller must exec as root inside dind
     * (not `-u uid:gid`): the init script refuses non-root.
     *
     * @return list<string>
     */
    public function stopEngineArgv(): array
    {
        return ['service', 'docker', 'stop'];
    }

    /**
     * @param  list<string>  $sidecarRefs
     * @return list<list<string>>
     */
    public function hostCleanupArgvs(EngineAccount $account, array $sidecarRefs = []): array
    {
        return DindAccountCleanup::hostCleanupArgvs(
            $account->username,
            $account->homeDir,
            $sidecarRefs
        );
    }
}
