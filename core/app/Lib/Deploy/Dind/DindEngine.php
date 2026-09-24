<?php

namespace App\Lib\Deploy\Dind;

use App\Lib\Deploy\Compose\ServiceLimits;
use App\Lib\Deploy\Engine\AccountStorage;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineFactory;
use App\Lib\Deploy\Engine\HostBuilder;
use App\Lib\Deploy\Engine\ImageStore;

/**
 * Docker-in-Docker on sysbox: every account's own daemon inside its own
 * container, data-root `~/docker` on the account's quota. A host-held image is
 * still streamed in (DindImageStore) and reclaim runs inside the account.
 */
final class DindEngine implements ContainerEngine
{
    /**
     * Service name of the account's DinD container in its own compose file.
     */
    public const SERVICE = 'dind';

    private ?DindImageStore $images = null;
    private ?DindHostBuilder $hostBuilder = null;
    private ?DindAccountStorage $storage = null;

    public function name(): string
    {
        return EngineFactory::DIND;
    }

    public function images(): ImageStore
    {
        return $this->images ??= new DindImageStore();
    }

    public function hostBuilder(): HostBuilder
    {
        // The one place the ceiling is read from config; DindHostBuilder stays
        // Laravel-free. Empty means it is sized from this host's MemTotal, with
        // 2g as ServiceLimits::hostBuildMemoryMb()'s floor.
        return $this->hostBuilder ??= new DindHostBuilder(
            self::resolveBuildMemory((string) config('deploy.build_memory', ''), self::hostMeminfo()),
            null,
            (string) config('deploy.build_network', BuildNetwork::DEFAULT_NAME)
        );
    }

    /**
     * The ceiling a build container gets: the operator's number if there is
     * one, otherwise one derived from this host.
     */
    public static function resolveBuildMemory(string $configured, string $procMeminfo): string
    {
        $configured = trim($configured);

        return $configured !== ''
            ? $configured
            : ServiceLimits::hostBuildMemoryMb($procMeminfo) . 'm';
    }

    /**
     * What this host has, for sizing a build container. `/proc/meminfo` is not
     * namespaced, so inside the engine container it reports host RAM rather
     * than the core container's 3g mem_limit. Unreadable yields ''.
     */
    private static function hostMeminfo(): string
    {
        return @file_get_contents('/proc/meminfo') ?: '';
    }

    public function storage(): AccountStorage
    {
        return $this->storage ??= new DindAccountStorage();
    }
}
