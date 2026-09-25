<?php

namespace App\Lib\Deploy\Engine;

/**
 * An account's own image and layer store: measuring it, reclaiming it, and
 * removing what it leaves behind.
 *
 * Each implementation decides whose disk a tenant's images live on — under DinD
 * that is `~/docker` inside the account's home, on the tenant's own quota. A
 * probe and its parser come as a pair, so an engine can swap `df` freely.
 *
 * `host*` methods run on the host; everything else runs inside the account.
 */
interface AccountStorage
{
    /**
     * The account's store, as a path valid **inside** the account. May be a
     * shell expression, not a literal path.
     */
    public function dataRoot(): string;

    /**
     * Inside the account: free space on the filesystem holding the store.
     *
     * @return list<string>
     */
    public function freeSpaceProbeArgv(): array;

    /**
     * Bytes free, from {@see freeSpaceProbeArgv()}'s output. Null when the
     * output cannot be read, so the caller leaves the store alone.
     */
    public function parseFreeBytes(string $output): ?int;

    /**
     * Inside the account: total size of the build cache.
     *
     * @return list<string>
     */
    public function buildCacheProbeArgv(): array;

    /**
     * Bytes held by the build cache, from {@see buildCacheProbeArgv()}'s
     * output. Null when unreadable.
     */
    public function parseBuildCacheBytes(string $output): ?int;

    /**
     * Inside the account: list its containers. Empty output means nothing is
     * running that a prune could disrupt, so an aggressive reclaim is safe.
     *
     * @return list<string>
     */
    public function containersProbeArgv(): array;

    /**
     * Inside the account: drop unused images and build caches through the
     * engine itself, so it keeps running and the deploy that asked for the
     * space carries on. Volumes stay. Only run with no containers left.
     *
     * @return list<list<string>>
     */
    public function reclaimArgvs(): array;

    /**
     * Inside the account: remove the store outright. Account deletion and
     * failed-deploy rollback, never the deploy path.
     */
    public function fullWipeScript(): string;

    /**
     * Inside the account: reclaim everything — containers, images, volumes.
     *
     * @return list<string>
     */
    public function pruneAllArgv(): array;

    /**
     * Inside the account: drop build caches only, for a deploy cancelled or
     * killed mid-build.
     *
     * One command per cache an engine keeps. All best-effort: a failure means
     * the disk stays fuller, not that the abort failed.
     *
     * @return list<list<string>>
     */
    public function pruneBuildCacheArgv(): array;

    /**
     * Inside the account: stop the engine so its store can be removed from
     * underneath it. Must run as root in the account container.
     *
     * @return list<string>
     */
    public function stopEngineArgv(): array;

    /**
     * On the host: reclaim everything this account leaves behind once it is
     * gone — its store, its host-side build cache, and the images it alone
     * caused the host to pull.
     *
     * @param list<string> $sidecarRefs host images already established as safe
     *        to remove. Filtering that list is the caller's job
     *        ({@see \App\Lib\Deploy\Dind\DindAccountCleanup::hostSidecarRefsToRemove()});
     *        an implementation removes what it is given.
     * @return list<string>
     */
    public function hostCleanupArgv(EngineAccount $account, array $sidecarRefs = []): array;
}
