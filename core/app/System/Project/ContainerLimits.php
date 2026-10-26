<?php

namespace App\System\Project;

use App\Lib\Limits\ResourceLimit;
use App\Models\User;
use App\System;

/**
 * The account container's resource limits as the compose templates take them.
 * An unset or unlimited limit is left out, so it never reaches Docker as a value.
 */
final class ContainerLimits
{
    /**
     * @return array{cpu_limit: string, memory_limit: string, device_read_bps: int|null, device_write_bps: int|null, block_device: string}
     */
    public static function templateVars(User $user, System $system): array
    {
        $deviceReadBps = self::enforcedInt($user->getDeviceReadBps());
        $deviceWriteBps = self::enforcedInt($user->getDeviceWriteBps());

        return [
            'cpu_limit' => (string) ResourceLimit::enforced($user->getCpuLimit()),
            'memory_limit' => (string) $user->effectiveMemoryLimit(),
            'device_read_bps' => $deviceReadBps,
            'device_write_bps' => $deviceWriteBps,
            'block_device' => $deviceReadBps !== null || $deviceWriteBps !== null
                ? $system->filesystem()->getHomeFilesystemParentBlockDevice()
                : '',
        ];
    }

    private static function enforcedInt(?int $value): ?int
    {
        $enforced = ResourceLimit::enforced($value);

        return $enforced === null ? null : (int) $enforced;
    }
}
