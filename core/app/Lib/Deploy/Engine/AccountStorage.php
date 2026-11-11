<?php

namespace App\Lib\Deploy\Engine;

use App\Lib\Deploy\Dind\DindAccountCleanup;

/**
 * An account's own image and layer store: measuring its disk and removing what it leaves behind, the probe paired
 * with its parser so an engine can swap `df`. `host*` methods run on the host, the rest inside the account.
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
     * Inside the account: remove the store outright. Account deletion and
     * failed-deploy rollback, never the deploy path.
     */
    public function fullWipeScript(): string;

    /**
     * Inside the account: stop the engine so its store can be removed from
     * underneath it. Must run as root in the account container.
     *
     * @return list<string>
     */
    public function stopEngineArgv(): array;

    /**
     * On the host: reclaim everything this account leaves behind once it is gone: its store, its host-side
     * build cache, and the images it alone caused the host to pull.
     *
     * @param  list<string>  $sidecarRefs  host images already established as safe
     *                                     to remove. Filtering that list is the caller's job
     *                                     ({@see DindAccountCleanup::hostSidecarRefsToRemove()});
     *                                     an implementation removes what it is given.
     * @return list<list<string>>
     */
    public function hostCleanupArgvs(EngineAccount $account, array $sidecarRefs = []): array;
}
