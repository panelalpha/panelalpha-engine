<?php

namespace App\System\Privileged;

/** Readings in the host's namespaces. */
final class RootProbes
{
    /** Filesystems a deploy or backup is sized by. */
    public const DISK_PATHS = ['/home', '/var/tmp', '/var/lib/docker'];

    private const AREA = 'probes';

    /** @return list<string> */
    public function blockDeviceParent(string $device): array
    {
        return $this->onHost(__FUNCTION__, [HostValues::device($device)]);
    }

    /** @return list<string> */
    public function blockDeviceType(string $device): array
    {
        return $this->onHost(__FUNCTION__, [HostValues::device($device)]);
    }

    /** @return list<string> */
    public function diskFree(string $path): array
    {
        return $this->onHost(__FUNCTION__, [self::diskPath($path)]);
    }

    /** @return list<string> */
    public function diskFreeInKilobytes(string $path): array
    {
        return $this->onHost(__FUNCTION__, [self::diskPath($path)]);
    }

    /**
     * Stays on the home's filesystem.
     *
     * @return list<string>
     */
    public function diskUsageInKilobytes(string $home): array
    {
        return $this->onHost(__FUNCTION__, [Arguments::path($home)]);
    }

    /**
     * systemd's Id, LoadState, ActiveState and ActiveEnterTimestamp of sysbox-fs and sysbox-mgr, the time in UTC:
     * PHP reads some zone abbreviations systemd prints as another zone's, e.g. IST as Israel's.
     *
     * @return list<string>
     */
    public function sysboxStates(): array
    {
        return $this->onHost(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function gitVersion(): array
    {
        return $this->onHost(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function hostImage(string $image): array
    {
        return $this->onHost(__FUNCTION__, [Arguments::image($image)]);
    }

    private static function diskPath(string $path): string
    {
        if (! in_array($path, self::DISK_PATHS, true)) {
            throw InvalidArgument::of('filesystem', $path);
        }

        return $path;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function onHost(string $action, array $values): array
    {
        return Root::action(self::AREA, $action, $values, true);
    }
}
