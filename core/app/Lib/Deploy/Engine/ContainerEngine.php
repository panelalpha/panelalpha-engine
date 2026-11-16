<?php

namespace App\Lib\Deploy\Engine;

/**
 * The container engine a hosting account's app runs on. Implementations build commands and System runs
 * them, so they are testable without a daemon; the one today is DindEngine (Docker-in-Docker on sysbox).
 */
interface ContainerEngine
{
    /** Stable identifier, as used by EngineFactory and the `DEPLOY_ENGINE` setting. */
    public function name(): string;

    /** Images between the host store and an account's store, plus the shared base images. */
    public function images(): ImageStore;

    /** Throwaway build containers on the host daemon: the Node compile and Composer resolve. */
    public function hostBuilder(): HostBuilder;

    /** An account's own image/layer store: probing, reclaiming, and removing it. */
    public function storage(): AccountStorage;
}
