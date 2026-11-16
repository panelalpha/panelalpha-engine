<?php

namespace App\System\Privileged;

/** ufw, fail2ban and their files, in the host's namespaces. */
final class RootFirewall
{
    public const RULES = '/etc/ufw/user.rules';

    public const SNAPSHOT = '/opt/panelalpha/shared-hosting/data/ufw-snapshot';

    public const TRUSTED_LISTS = ['/etc/fail2ban/panelalpha-ignoreip', '/etc/fail2ban/panelalpha-api-ignoreip'];

    public const LOCKS = ['/opt/panelalpha/shared-hosting/data/ufw.lock', '/opt/panelalpha/shared-hosting/data/ufw-trusted.lock'];

    private const AREA = 'firewall';

    /** @return list<string> */
    public function version(): array
    {
        return $this->onHost(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function status(): array
    {
        return $this->onHost(__FUNCTION__, []);
    }

    /**
     * {@see HostValues::ufwWords()}
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    public function ufw(array $words): array
    {
        return $this->onHost(__FUNCTION__, HostValues::ufwWords($words));
    }

    /**
     * Stored rules ufw could not load, one per line.
     *
     * @return list<string>
     */
    public function refusedRules(): array
    {
        return $this->onHost(__FUNCTION__, []);
    }

    /**
     * Reloads the trusted lists into fail2ban.
     *
     * @return list<string>
     */
    public function applyTrustedLists(): array
    {
        return $this->onHost(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function rules(): array
    {
        return $this->onHost(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function rules6(): array
    {
        return $this->onHost(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function readTrustedList(string $path): array
    {
        return $this->onHost(__FUNCTION__, [self::trustedList($path)]);
    }

    /** @return list<string> */
    public function writeTrustedList(string $path, string $list): array
    {
        return $this->onHost(__FUNCTION__, [self::trustedList($path), HostValues::trustedList($list)]);
    }

    /** @return list<string> */
    public function unban(string $address): array
    {
        return $this->onHost(__FUNCTION__, [HostValues::addressOrNetwork($address)]);
    }

    /** @return list<string> */
    public function ensureLockFile(string $file): array
    {
        if (! in_array($file, self::LOCKS, true)) {
            throw InvalidArgument::of('firewall lock', $file);
        }

        return $this->onHost(__FUNCTION__, [$file]);
    }

    /**
     * Prints ufw.conf's ENABLED.
     *
     * @return list<string>
     */
    public function snapshotRules(): array
    {
        return $this->onHost(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function restoreRules(): array
    {
        return $this->onHost(__FUNCTION__, []);
    }

    /**
     * Like `+02:00`.
     *
     * @return list<string>
     */
    public function timezoneOffset(): array
    {
        return $this->onHost(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function fail2banLog(int $lines): array
    {
        return $this->onHost(__FUNCTION__, [HostValues::positive($lines, 'line count')]);
    }

    /** @return list<string> */
    public function kernelBlockLog(int $lines): array
    {
        return $this->onHost(__FUNCTION__, [HostValues::positive($lines, 'line count')]);
    }

    /** @return list<string> */
    public function ufwLog(int $lines): array
    {
        return $this->onHost(__FUNCTION__, [HostValues::positive($lines, 'line count')]);
    }

    private static function trustedList(string $path): string
    {
        if (! in_array($path, self::TRUSTED_LISTS, true)) {
            throw InvalidArgument::of('trusted list', $path);
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
