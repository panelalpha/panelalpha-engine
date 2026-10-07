<?php

namespace App\Lib\Deploy\Dind;

use App\Lib\Deploy\Compose\ServiceLimits;
use App\Lib\Deploy\Engine\AccountStorage;
use App\Lib\Deploy\Engine\BuildMemory;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineFactory;
use App\Lib\Deploy\Engine\HostBuilder;
use App\Lib\Deploy\Engine\ImageStore;
use App\Lib\Host\HostMemory;
use App\Lib\Host\HostMemoryProbe;

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
    /** @var array<string, DindHostBuilder> keyed by memory limit */
    private array $hostBuilders = [];
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
        // The one place the ceiling is read from config; DindHostBuilder stays Laravel-free.
        $memory = self::buildMemory((string) config('deploy.build_memory', ''), HostMemoryProbe::current());

        return $this->hostBuilders[$memory->limit] ??= new DindHostBuilder(
            $memory->limit,
            null,
            (string) config('deploy.build_network', BuildNetwork::DEFAULT_NAME),
            $memory
        );
    }

    public static function builderFor(string $configured, HostMemory $host): DindHostBuilder
    {
        $memory = self::buildMemory($configured, $host);

        return new DindHostBuilder($memory->limit, null, null, $memory);
    }

    /** The most a build gets unless DEPLOY_BUILD_MEMORY asks for more. */
    public const MAX_BUILD_MEMORY_MB = 8192;

    /**
     * 8 GB, or `DEPLOY_BUILD_MEMORY` when it parses; never more than
     * {@see buildCeilingMb()}.
     */
    public static function buildMemory(string $configured, HostMemory $host): BuildMemory
    {
        $maxMb = self::buildCeilingMb($host);
        $configuredMb = ServiceLimits::toMegabytes(trim($configured)) ?? 0;
        if ($configuredMb > 0) {
            return $maxMb > 0 && $configuredMb > $maxMb
                ? new BuildMemory($maxMb . 'm', BuildMemory::SETTING_CAPPED, $host)
                : new BuildMemory(trim($configured), BuildMemory::SETTING, $host);
        }

        // An unreadable host leaves the builder's default.
        return new BuildMemory(
            $maxMb > 0 ? min(self::MAX_BUILD_MEMORY_MB, $maxMb) . 'm' : '',
            BuildMemory::HOST,
            $host
        );
    }

    /** Half the server's RAM, and never more than its RAM less DEPLOY_ENGINE_MEMORY. 0 when unreadable. */
    public static function buildCeilingMb(HostMemory $host): int
    {
        return min($host->maxProjectMb(), intdiv($host->totalMb, 2));
    }

    public function storage(): AccountStorage
    {
        return $this->storage ??= new DindAccountStorage();
    }
}
