<?php

namespace App\Lib\Deploy\Engine;

use App\Lib\Deploy\CacheManager\ImageTransfer;
use App\System;

/**
 * Getting an image to where a build or a `compose up` can use it.
 *
 * Two stores are in play: the **host** store, shared by every account and
 * where an image is pulled or built exactly once, and an **account** store
 * private to one tenant. The engine decides the route: for DinD, registries
 * only (the host pushes to the cache registry and the account pulls, or the
 * account pulls from the image's own registry), never a stream between the two
 * daemons; an engine whose halves share one store would make it a no-op.
 *
 * Every method returns a command for {@see System} to run: `host*` runs on
 * the host, everything else inside the account.
 */
interface ImageStore
{
    /**
     * Build a context-free Dockerfile on the **host** under $tag, once; idempotent,
     * since a deploy calls it on every run. $pull false builds FROM an image only this host has.
     */
    public function hostBuildCommand(string $tag, string $dockerfile, bool $rebuild = false, bool $pull = true): string;

    /**
     * Push a host image to where accounts pull shared images from, so an
     * account can have it without the host being asked again.
     */
    public function hostPublishCommand(string $image): string;

    /**
     * Host store → account store for an image the host holds, such as one
     * {@see hostBuildCommand()} produced, where a registry pull would only 404.
     */
    public function loadFromHostCommand(EngineAccount $account, string $image): string;

    /**
     * Get one image into the account by whatever route works, printing one
     * line that says which. $ours: built on the host, so it comes from there;
     * otherwise from its own registry. $private: the project has a login for
     * its registry, so it never goes through a shared one; $dockerConfig is
     * the client config holding that login.
     */
    public function seedCommand(
        EngineAccount $account,
        string $image,
        bool $ours,
        bool $private = false,
        ?string $dockerConfig = null,
    ): string;

    /**
     * {@see seedCommand()} for a public image the host should fetch on the
     * accounts' behalf, once, rather than each account from its own registry:
     * one no pull-through cache reaches, such as Railpack's on ghcr.io.
     */
    public function seedThroughHostCommand(EngineAccount $account, string $image): string;

    /**
     * Seed several images at once, never more than $concurrency in flight.
     *
     * One script, not N commands, so a cancelled deploy has a single PID to
     * kill. Returned as a script for `['bash', '-c', …]`.
     *
     * @param  list<string>  $images
     */
    public function parallelImportCommand(EngineAccount $account, array $images, int $concurrency): string;

    /**
     * Inside the account: every image tag its store holds, one per line, so
     * the caller can ask once instead of once per image.
     *
     * @return list<string>
     */
    public function listImagesArgv(): array;

    /**
     * Inside the account: non-empty output when the store has $image and can
     * read its config, empty output or a failure otherwise.
     *
     * @return list<string>
     */
    public function imageIdArgv(string $image): array;

    /**
     * On the host: the account's own container's mounts, so a registry
     * refresh can tell whether its daemon.json is a file this engine renders
     * and can safely rewrite in place, or still an older account's own.
     *
     * @return list<string>
     */
    public function hostAccountMountsArgv(EngineAccount $account): array;

    /**
     * On the host: the account's processes with their host-visible PIDs, so
     * its daemon can be signalled from the host without a shell inside it.
     *
     * @return list<string>
     */
    public function hostAccountProcessesArgv(EngineAccount $account): array;

    /**
     * On the host: signal the account's daemon, found by a host-visible PID.
     *
     * @return list<string>
     */
    public function hostSignalDockerdArgv(int $pid): array;

    /**
     * Inside the account: $image's declared ports as JSON, in the
     * `{"5432/tcp":{}}` shape {@see ImageTransfer::parseExposedPorts()}
     * reads.
     *
     * @return list<string>
     */
    public function imageExposedPortsArgv(string $image): array;

    /**
     * On the host: succeed iff the host store already holds $image, i.e.
     * whether providing it to an account is a registry transfer, not a build.
     *
     * @return list<string>
     */
    public function hostImageInspectArgv(string $image): array;

    /**
     * On the host: the same port metadata as {@see imageExposedPortsArgv()},
     * read from the host store — what lets an unfamiliar vendor image be
     * classified before any account has it.
     *
     * @return list<string>
     */
    public function hostImageExposedPortsArgv(string $image): array;

    /**
     * On the host: get $image onto the host store when it is not there, pulling
     * one of ours from where accounts pull shared images. A host build runs the
     * image by name, and docker.io does not publish our tags. Never fatal, so a
     * deploy chains it before every host build.
     *
     * @return list<string>
     */
    public function hostEnsureImageArgv(string $image): array;

    /**
     * On the engine: rebuild $image on the host from $dockerfile and push it
     * to the cache registry when the host and the registry both lack it. Holds
     * a lock of that image's own, so a second deploy waits and then finds the
     * image the first one built instead of building it again; only the push
     * takes the push lock.
     */
    public function hostRebuildCommand(string $image, string $dockerfile): string;

    /**
     * Pull a public base image the account already holds again, so a moving
     * tag follows upstream's patch releases. Prints whether it changed; fails
     * when the pull does.
     */
    public function refreshCommand(EngineAccount $account, string $image): string;

    /**
     * On the host: pull a public build image again before a host build runs
     * it. Never fatal; `['true']` when there is nothing upstream to follow.
     *
     * @return list<string>
     */
    public function hostRefreshImageArgv(string $image): array;
}
