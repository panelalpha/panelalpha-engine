<?php

namespace App\Lib\Deploy\Engine;

use App\Lib\Deploy\Platform\Runtime\Php\PhpHostBuild;

/**
 * Throwaway build containers on the **host**, working on an account's
 * ~/project.
 *
 * The host already has the base image and a warm package cache, while a fresh
 * account has neither and reaches a registry through nested NAT. Output is
 * written straight back into ~/project, which the account's own stack serves.
 *
 * A host-daemon container with the customer's repository in it, so every
 * implementation owes the same guarantees: run as the account's uid:gid, no
 * new privileges with all capabilities dropped, bounded memory and pids, and a
 * project directory that is exactly the account's own ~/project.
 *
 * Every method returns argv for {@see \App\System} to run on the host.
 */
interface HostBuilder
{
    /**
     * Run $install then $build for the account's JS project in $image.
     *
     * @param string $image compiler image the recipe picked (Node or bun)
     * @param array<string, string|int> $env extra environment the recipe asked
     *        for (NITRO_PRESET, NODE_ENV, …); keys that are not plain
     *        SHOUTING_CASE are dropped
     * @param bool $isolateNodeModules keep node_modules in the host cache
     *        instead of leaving it in the account's ~/project. False only for
     *        a standalone Node runtime, which has to serve the tree it built.
     * @param string $appRoot application subtree the build runs in, '' for
     *        the checkout root
     * @return list<string>
     */
    public function nodeBuildArgv(
        EngineAccount $account,
        string $image,
        string $install,
        string $build,
        array $env = [],
        bool $isolateNodeModules = true,
        bool $isNode = true,
        string $appRoot = ''
    ): array;

    /**
     * Run a PHP project's own build — its manifest's build-stage commands —
     * on the host, in the shared base image the account will be served from.
     *
     * There is no per-project image to put `composer install` in, so it runs
     * here and writes `vendor/` into ~/project, which *is* the document root:
     * the running container picks it up without a restart.
     *
     * @param string $image the shared PHP base assigned to this account
     * @param string $script build steps, already ordered
     * @param string $appRoot application subtree inside the repository, '' when
     *        they are the same
     * @param ?string $manifest a manifest for Composer to resolve from
     *        instead of composer.json, exported as COMPOSER; see
     *        {@see PhpHostBuild::runtimeManifest()}
     * @return list<string>
     */
    public function phpBuildArgv(
        EngineAccount $account,
        string $image,
        string $script,
        string $appRoot = '',
        bool $withCache = true,
        ?string $manifest = null
    ): array;

    /**
     * Resolve vendor/ for a PHP project whose frontend build imports from it.
     *
     * Scripts and plugins stay disabled: both execute arbitrary PHP out of the
     * customer's repository, and this runs on the host daemon, outside the
     * account's sandbox.
     *
     * @return list<string>
     */
    public function composerInstallArgv(
        EngineAccount $account,
        ?string $phpVersion = null,
        ?string $manifest = null,
        string $appRoot = ''
    ): array;

    /**
     * Create the account's host-side package cache directories and hand them
     * to its uid:gid, so the unprivileged build container can write them.
     *
     * @return list<string>
     */
    public function prepareCacheArgv(EngineAccount $account): array;

    /** The memory, in MB, every build container above is started with. */
    public function memoryLimitMb(): int;

    /** Where that figure came from, for the deploy log. */
    public function memoryOrigin(): BuildMemory;
}
