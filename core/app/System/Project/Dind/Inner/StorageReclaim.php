<?php

namespace App\System\Project\Dind\Inner;

use App\Lib\Deploy\Dind\DindBuildStorage;
use App\Lib\Deploy\Engine\AccountStorage;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\InnerDocker;

/**
 * The inner daemon's disk (data-root under ~/docker), which comes out of the account's quota.
 * Pruning everything is gated on the account running no containers, not on how full the disk is.
 */
class StorageReclaim
{
    private const PRUNE_SECONDS = 300;

    private InnerDocker $inner;

    public function __construct(InnerDocker $inner)
    {
        $this->inner = $inner;
    }

    /**
     * Clear the build cache before a heavy build when the account is low on disk and nothing running could be
     * disrupted; best effort, so a probe that times out or fails is logged and the build goes on.
     */
    public function reclaimIfNeeded(): void
    {
        try {
            $needed = $this->canAggressivelyReclaim() && $this->isLowOnDiskForBuild();
        } catch (\Throwable $e) {
            $this->inner->host()->logWarn('Could not check inner Docker disk before the build: '.$e->getMessage());

            return;
        }

        if ($needed) {
            $this->reclaim(false);
        }
    }

    private function isLowOnDiskForBuild(): bool
    {
        $dind = $this->inner->dind();
        $storage = $this->storage();
        $availableBytes = $storage->parseFreeBytes(
            $dind->shell()->execAsUserQuiet($storage->freeSpaceProbeArgv(), [], 60)
        );
        $buildCacheBytes = $dind->accountDaemon()->buildCacheBytes(120);

        return DindBuildStorage::shouldReclaimBeforeBuild($availableBytes, $buildCacheBytes, false);
    }

    /**
     * Safe to prune everything only when the account holds no container, stopped ones included. A daemon that
     * cannot say throws rather than reading as holding none.
     */
    public function canAggressivelyReclaim(): bool
    {
        return $this->inner->dind()->accountDaemon()->containerIds(true, [], 60) === [];
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
        $this->prune('unused data', static fn (AccountDaemon $daemon) => $daemon->pruneSystem(false, self::PRUNE_SECONDS));
        $this->pruneBuildCache();
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
        $this->pruneBuildCache();
        $this->prune('unused images', static fn (AccountDaemon $daemon) => $daemon->pruneImages(self::PRUNE_SECONDS));
    }

    private function pruneBuildCache(): void
    {
        $this->prune('build cache', static fn (AccountDaemon $daemon) => $daemon->pruneBuildCache(self::PRUNE_SECONDS));
    }

    /**
     * Best-effort: a prune that times out or meets a daemon error must not fail
     * the deploy it was meant to help, and the next prune still runs.
     *
     * @param  \Closure(AccountDaemon): void  $prune
     */
    private function prune(string $what, \Closure $prune): void
    {
        $dind = $this->inner->dind();
        try {
            $dind->shell()->logger()?->throwIfCancelled();
            $prune($dind->accountDaemon());
        } catch (\Throwable $e) {
            $this->inner->host()->logWarn('Could not reclaim inner Docker disk ('.$what.'): '.$e->getMessage());
        }
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
