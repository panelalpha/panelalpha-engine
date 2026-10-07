<?php

namespace App\System\Project\Dind\Inner;

use App\System\Project\Dind\InnerDocker;
use App\Lib\Deploy\Dind\DindBuildStorage;
use App\Lib\Deploy\Engine\AccountStorage;

/**
 * The inner daemon's disk, which is the account's disk.
 *
 * Its data-root lives under ~/docker rather than on the host, so a build
 * cache nobody clears comes out of the customer's quota. Reclaiming is only
 * safe while the account runs no containers — pruning under a live stack
 * would take images out from under it — so every path here is gated on that
 * check rather than on how full the disk is.
 */
class StorageReclaim
{
    private InnerDocker $inner;

    public function __construct(InnerDocker $inner)
    {
        $this->inner = $inner;
    }

    /**
     * Clear the build cache before a heavy build when the account is low on
     * disk and nothing is running that could be disrupted.
     */
    public function reclaimIfNeeded(): void
    {
        if (!$this->canAggressivelyReclaim()) {
            return;
        }

        $shell = $this->inner->dind()->shell();
        $storage = $this->storage();
        $availableBytes = $storage->parseFreeBytes(
            $shell->execAsUserQuiet($storage->freeSpaceProbeArgv(), [], 60)
        );
        $buildCacheBytes = $storage->parseBuildCacheBytes(
            $shell->execAsUserQuiet($storage->buildCacheProbeArgv(), [], 120)
        );

        if (!DindBuildStorage::shouldReclaimBeforeBuild($availableBytes, $buildCacheBytes, false)) {
            return;
        }

        $this->reclaim(false);
    }

    /**
     * Safe to prune everything only when the account runs no containers.
     */
    public function canAggressivelyReclaim(): bool
    {
        $containers = trim(
            $this->inner->dind()->shell()->execAsUserQuiet($this->storage()->containersProbeArgv(), [], 60)
        );

        return $containers === '';
    }

    public function reclaim(bool $emergency): void
    {
        $this->inner->host()->logInfo(
            $emergency
                ? 'Clearing inner Docker cache after disk-full build failure'
                : 'Clearing inner Docker cache before heavy build'
        );
        // Through dockerd, which keeps running: the account has no containers
        // here, so nothing depends on what goes.
        foreach ($this->storage()->reclaimArgvs() as $argv) {
            $this->inner->dind()->shell()->exec($argv, [], 300);
        }
    }

    /**
     * After a step was stopped for disk. With the app still up (a rebuild
     * keeps it serving) only what no container uses goes: the build cache and
     * unused images.
     */
    public function reclaimAfterDiskLimit(): void
    {
        if ($this->canAggressivelyReclaim()) {
            // The killed build's half-written layer stays leased, and unprunable,
            // until the daemon restarts (1.2 GB measured); nothing runs to lose.
            $this->restartEngine();
            $this->reclaim(true);

            return;
        }

        $this->inner->host()->logInfo('Clearing inner Docker build cache and unused images after the disk limit');
        foreach ($this->whileRunningArgvs() as $argv) {
            $this->inner->dind()->shell()->exec($argv, [], 300);
        }
    }

    /**
     * What goes while the app still runs: nothing a container uses.
     *
     * @return list<list<string>>
     */
    public function whileRunningArgvs(): array
    {
        return [...$this->storage()->pruneBuildCacheArgv(), ['docker', 'image', 'prune', '-af']];
    }

    protected function restartEngine(): void
    {
        $dind = $this->inner->dind();
        $dind->shell()->runProcess($dind->services()->stopArgv('docker'), [], 120);
        $dind->shell()->runProcess($dind->services()->startArgv('docker'), [], 60);
        $dind->awaitReady(10, 2);
    }

    /**
     * Part of deleting the account. Quiet: a deploy log left cancelled -- a
     * delete may follow a cancelled deploy -- must not stop it.
     */
    public function wipeDataRoot(): void
    {
        $dind = $this->inner->dind();
        $dind->shell()->runProcess($dind->services()->stopArgv('docker'), [], 120);
        $dind->shell()->execQuiet(['sh', '-lc', $this->storage()->fullWipeScript()], [], 300);
    }

    private function storage(): AccountStorage
    {
        return $this->inner->dind()->engine()->storage();
    }
}
