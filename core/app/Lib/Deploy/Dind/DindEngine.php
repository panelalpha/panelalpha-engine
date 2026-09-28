<?php

namespace App\Lib\Deploy\Dind;

use App\Lib\Deploy\Compose\ServiceLimits;
use App\Lib\Deploy\Engine\AccountStorage;
use App\Lib\Deploy\Engine\BuildMemory;
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
    /** @var array<string, DindHostBuilder> */
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

    public function hostBuilder(?int $projectMemoryMb = null): HostBuilder
    {
        // The one place the ceiling is read from config; DindHostBuilder stays
        // Laravel-free. One builder per figure, since projects differ.
        $memory = self::buildMemory(
            (string) config('deploy.build_memory', ''),
            self::hostMeminfo(),
            $projectMemoryMb
        );
        $key = implode('|', [$memory->limit, $memory->source, $memory->serverShareMb, $memory->projectLimitMb]);

        return $this->hostBuilders[$key] ??= new DindHostBuilder(
            $memory->limit,
            null,
            (string) config('deploy.build_network', BuildNetwork::DEFAULT_NAME),
            $memory
        );
    }

    public static function builderFor(string $configured, string $procMeminfo, ?int $projectMemoryMb = null): DindHostBuilder
    {
        $memory = self::buildMemory($configured, $procMeminfo, $projectMemoryMb);

        return new DindHostBuilder($memory->limit, null, null, $memory);
    }

    /**
     * The ceiling a build container gets: the operator's number if there is
     * one, otherwise one derived from this host.
     */
    public static function resolveBuildMemory(string $configured, string $procMeminfo, ?int $projectMemoryMb = null): string
    {
        return self::buildMemory($configured, $procMeminfo, $projectMemoryMb)->limit;
    }

    /**
     * `DEPLOY_BUILD_MEMORY` wins outright: it is the operator's decision for
     * the server, and a plan must not undo a cap set on purpose. Unset, the
     * build gets the server share, raised (never lowered) to the project's
     * memory limit, up to {@see ServiceLimits::projectBuildMemoryCapMb()}.
     */
    public static function buildMemory(string $configured, string $procMeminfo, ?int $projectMemoryMb = null): BuildMemory
    {
        $configured = trim($configured);
        if ($configured !== '') {
            return new BuildMemory($configured, BuildMemory::SETTING);
        }

        $share = ServiceLimits::hostBuildMemoryMb($procMeminfo);
        $cap = ServiceLimits::projectBuildMemoryCapMb($procMeminfo);
        if ($projectMemoryMb === null || $projectMemoryMb <= $share || $cap === null || $cap <= $share) {
            return new BuildMemory($share . 'm', BuildMemory::SERVER, $share, $projectMemoryMb);
        }

        $mb = min($projectMemoryMb, $cap);

        return new BuildMemory(
            $mb . 'm',
            $mb < $projectMemoryMb ? BuildMemory::PROJECT_CAPPED : BuildMemory::PROJECT,
            $share,
            $projectMemoryMb
        );
    }

    /**
     * The memory limit an account container runs under, in MB; null means none.
     *
     * The plan's own number wins, and 0 there stays an explicit "unlimited".
     * Unset used to mean unlimited too, which let one tenant's build take the
     * whole host (engine#110): now it means the operator's
     * `DEPLOY_ACCOUNT_MEMORY` (`0` opts back out), else half of this host.
     */
    public static function resolveAccountMemoryMb(?int $planLimitMb, string $configured, string $procMeminfo): ?int
    {
        if ($planLimitMb !== null) {
            return $planLimitMb > 0 ? $planLimitMb : null;
        }
        $configured = trim($configured);
        if ($configured === '0') {
            return null;
        }

        return ServiceLimits::toMegabytes($configured) ?? ServiceLimits::accountDefaultMemoryMb($procMeminfo);
    }

    public static function accountMemoryMb(?int $planLimitMb): ?int
    {
        return self::resolveAccountMemoryMb($planLimitMb, (string) config('deploy.account_memory', ''), self::hostMeminfo());
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
