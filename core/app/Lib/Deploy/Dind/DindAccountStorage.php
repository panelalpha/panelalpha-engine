<?php

namespace App\Lib\Deploy\Dind;

use App\Lib\Deploy\Engine\AccountStorage;
use App\Lib\Deploy\Engine\EngineAccount;

/**
 * The nested daemon's data-root: `~/docker` inside the account's own home, so a
 * full store fills the tenant's quota while the host has terabytes free. The
 * probes run inside the account; deletion removes the tree explicitly.
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
        return ['sh', '-lc', 'df -Pk "' . $this->dataRoot() . '" 2>/dev/null || true'];
    }

    public function parseFreeBytes(string $output): ?int
    {
        return DindBuildStorage::parseDfAvailableBytes($output);
    }

    /**
     * @return list<string>
     */
    public function buildCacheProbeArgv(): array
    {
        return ['sh', '-lc', 'docker buildx du 2>/dev/null || true'];
    }

    public function parseBuildCacheBytes(string $output): ?int
    {
        return DindBuildStorage::parseBuildxTotalBytes($output);
    }

    /**
     * `-a`, not just running: a stopped container still owns layers a prune
     * would take.
     *
     * @return list<string>
     */
    public function containersProbeArgv(): array
    {
        return ['sh', '-lc', 'docker ps -aq 2>/dev/null || true'];
    }

    /**
     * `system prune -a` takes the unused images; the builder prunes take
     * BuildKit's cache under both the classic builder and buildx.
     *
     * @return list<list<string>>
     */
    public function reclaimArgvs(): array
    {
        return [['docker', 'system', 'prune', '-af'], ...$this->pruneBuildCacheArgv()];
    }

    public function fullWipeScript(): string
    {
        return DindBuildStorage::fullWipeScript();
    }

    /**
     * @return list<string>
     */
    public function pruneAllArgv(): array
    {
        return ['docker', 'system', 'prune', '-af', '--volumes'];
    }

    /**
     * Two prunes: BuildKit's cache is reachable under both the classic builder
     * and buildx, and an interrupted build can leave records in either.
     *
     * @return list<list<string>>
     */
    public function pruneBuildCacheArgv(): array
    {
        return [
            ['docker', 'builder', 'prune', '-af'],
            ['docker', 'buildx', 'prune', '-af'],
        ];
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
     * @param list<string> $sidecarRefs
     * @return list<string>
     */
    public function hostCleanupArgv(EngineAccount $account, array $sidecarRefs = []): array
    {
        return DindAccountCleanup::hostCleanupArgv(
            $account->username,
            $account->homeDir,
            $sidecarRefs
        );
    }
}
