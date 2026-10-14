<?php

namespace App\System\Project\Dind\Inner;

use App\System\Project\Dind\InnerDocker;
use App\Lib\Deploy\Dind\DindBuildStorage;
use App\Lib\Deploy\Engine\AccountStorage;

/**
 * The inner daemon's disk (data-root under ~/docker), which comes out of the account's quota.
 * Pruning everything is gated on the account running no containers, not on how full the disk is.
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
     * disk and nothing is running that could be disrupted. Best-effort like the
     * prune itself: a probe that times out or fails is logged and the build goes on.
     */
    public function reclaimIfNeeded(): void
    {
        try {
            $needed = $this->canAggressivelyReclaim() && $this->isLowOnDiskForBuild();
        } catch (\Throwable $e) {
            $this->inner->host()->logWarn('Could not check inner Docker disk before the build: ' . $e->getMessage());

            return;
        }

        if ($needed) {
            $this->reclaim(false);
        }
    }

    private function isLowOnDiskForBuild(): bool
    {
        $shell = $this->inner->dind()->shell();
        $storage = $this->storage();
        $availableBytes = $storage->parseFreeBytes(
            $shell->execAsUserQuiet($storage->freeSpaceProbeArgv(), [], 60)
        );
        $buildCacheBytes = $storage->parseBuildCacheBytes(
            $shell->execAsUserQuiet($storage->buildCacheProbeArgv(), [], 120)
        );

        return DindBuildStorage::shouldReclaimBeforeBuild($availableBytes, $buildCacheBytes, false);
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

    /**
     * Prunes through dockerd, which keeps running: the account has no containers here,
     * so nothing depends on what goes.
     */
    public function reclaim(bool $emergency): void
    {
        $this->inner->host()->logInfo(
            $emergency
                ? 'Clearing inner Docker cache after disk-full build failure'
                : 'Clearing inner Docker cache before heavy build'
        );
        foreach ($this->storage()->reclaimArgvs() as $argv) {
            $this->prune($argv);
        }
    }

    /**
     * After a step was stopped for disk. With nothing running the daemon restarts first, since the killed
     * build's half-written layer stays leased until it does; with the app still up only unused data goes.
     */
    public function reclaimAfterDiskLimit(): void
    {
        if ($this->canAggressivelyReclaim()) {
            $this->restartEngine();
            $this->reclaim(true);

            return;
        }

        $this->inner->host()->logInfo('Clearing inner Docker build cache and unused images after the disk limit');
        foreach ($this->whileRunningArgvs() as $argv) {
            $this->prune($argv);
        }
    }

    /**
     * Best-effort: a prune that times out or meets a daemon error must not fail
     * the deploy it was meant to help, and the next prune still runs.
     *
     * @param list<string> $argv
     */
    private function prune(array $argv): void
    {
        try {
            $this->inner->dind()->shell()->exec($argv, [], 300);
        } catch (\Throwable $e) {
            $this->inner->host()->logWarn('Could not reclaim inner Docker disk (' . implode(' ', $argv) . '): ' . $e->getMessage());
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

    /** Part of deleting the account; quiet, so a deploy log left cancelled before the delete cannot stop it. */
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
