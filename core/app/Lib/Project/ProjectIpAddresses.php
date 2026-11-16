<?php

namespace App\Lib\Project;

use App\Models\IpAssigned;
use App\Models\IpSubnet;
use App\Models\Ipv4NatMap;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Tests\Unit\UserIpAddressesTest;

/**
 * The addresses a project answers on: its dedicated IPs, else the engine's defaults, through the NAT map
 * in NAT mode. Moved out of User as it was ({@see UserIpAddressesTest}).
 */
final class ProjectIpAddresses
{
    public function __construct(private readonly User $user) {}

    public function dedicatedIpv4Enabled(): bool
    {
        $default = false;
        $details = $this->user->getDetails();
        if (empty($details['dedicated_ipv4']) || ! is_bool($details['dedicated_ipv4'])) {
            return $default;
        }

        return $details['dedicated_ipv4'];
    }

    public function dedicatedIpv6Enabled(): bool
    {
        $default = false;
        $details = $this->user->getDetails();
        if (empty($details['dedicated_ipv6']) || ! is_bool($details['dedicated_ipv6'])) {
            return $default;
        }

        return $details['dedicated_ipv6'];
    }

    /**
     * In NAT mode local IPs map to their public counterparts, which clients and DNS see.
     *
     * @return array{
     *   ipv4: list<string>,
     *   ipv6: list<string>,
     * }
     */
    public function getIpAddresses(): array
    {
        $ips = $this->resolveIpAddresses();

        if (Ipv4NatMap::isNatModeEnabled()) {
            $localToPublic = Ipv4NatMap::getLocalToPublicMap();
            $ips['ipv4'] = self::mapIps($ips['ipv4'], $localToPublic);
        }

        return $ips;
    }

    /**
     * The IPs for webserver bind/listen directives, unfiltered: a public default_ipv4 is bound as it is. In NAT
     * mode public IPs are translated back to local ones.
     *
     * @return array{
     *   ipv4: array<string>,
     *   ipv6: array<string>,
     * }
     */
    public function getBindIpAddresses(): array
    {
        $ips = $this->resolveIpAddresses();

        if (Ipv4NatMap::isNatModeEnabled()) {
            $publicToLocal = Ipv4NatMap::getPublicToLocalMap();
            $ips['ipv4'] = self::mapIps($ips['ipv4'], $publicToLocal);
        }

        return $ips;
    }

    /**
     * @return array{
     *   ipv4: array<string>,
     *   ipv6: array<string>,
     * }
     */
    private function resolveIpAddresses(): array
    {
        $ips = [
            'ipv4' => [],
            'ipv6' => [],
        ];

        if (! empty(Setting::get('disable-user-ip-assign'))) {
            return $ips;
        }

        $assigned = $this->user->getAssignedIpAddresses();
        if (! empty($assigned)) {
            foreach ($assigned as $ip) {
                if (filter_var($ip->ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $ips['ipv6'][] = $ip->ip_address;

                    continue;
                }
                $ips['ipv4'][] = $ip->ip_address;
            }
        }

        $defaults = self::defaultIpAddresses();
        if (empty($ips['ipv4'])) {
            $ips['ipv4'] = $defaults['ipv4'];
        }
        if (empty($ips['ipv6'])) {
            $ips['ipv6'] = $defaults['ipv6'];
        }

        return $ips;
    }

    /**
     * The engine's own addresses, user-independent on purpose: the webserver declares them from install time,
     * before any user exists ({@see AbstractWebserver::getAllIpsVars()}).
     *
     * @return array{
     *   ipv4: array<string>,
     *   ipv6: array<string>,
     * }
     */
    public static function defaultIpAddresses(): array
    {
        $ips = [
            'ipv4' => [],
            'ipv6' => [],
        ];

        if (! empty(Setting::get('disable-user-ip-assign'))) {
            return $ips;
        }

        $defaultIpv4 = Setting::get('default_ipv4');
        if (! empty($defaultIpv4)) {
            $ips['ipv4'][] = $defaultIpv4;
        }

        $defaultIpv6 = Setting::get('default_ipv6');
        if (! empty($defaultIpv6)) {
            $ips['ipv6'][] = $defaultIpv6;
        }

        return $ips;
    }

    /**
     * {@see defaultIpAddresses()} translated for binding, as {@see getBindIpAddresses()} translates a user's own.
     *
     * @return array{
     *   ipv4: array<string>,
     *   ipv6: array<string>,
     * }
     */
    public static function defaultBindIpAddresses(): array
    {
        $ips = self::defaultIpAddresses();

        if (Ipv4NatMap::isNatModeEnabled()) {
            $ips['ipv4'] = self::mapIps($ips['ipv4'], Ipv4NatMap::getPublicToLocalMap());
        }

        return $ips;
    }

    /**
     * @param  array<string>  $ips
     * @param  array<string, string>  $map
     * @return array<string>
     */
    private static function mapIps(array $ips, array $map): array
    {
        $mapped = [];
        foreach ($ips as $ip) {
            $mapped[] = $map[$ip] ?? $ip;
        }

        return array_values(array_unique($mapped));
    }

    public function assignFreeDedicatedIpv4(): bool
    {
        /** @var Collection<array-key, IpSubnet> */
        $subnets = IpSubnet::query()
            ->where('family', 4)
            ->where('is_shared', 0)
            ->get();
        /** @var array<IpSubnet> */
        $subnets = $subnets->all();

        /** @var string */
        $defaultIpv4 = Setting::get('default_ipv4');

        foreach ($subnets as $subnet) {
            if ($freeIp = $subnet->findFreeIp([$defaultIpv4])) {
                IpAssigned::create([
                    'user_id' => $this->user->id,
                    'ip_subnet_id' => $subnet->id,
                    'ip_address' => $freeIp,
                ]);

                return true;
            }
        }

        return false;
    }

    public function assignFreeDedicatedIpv6(): bool
    {
        /** @var Collection<array-key, IpSubnet> */
        $subnets = IpSubnet::query()
            ->where('family', 6)
            ->where('is_shared', 0)
            ->get();
        /** @var array<IpSubnet> */
        $subnets = $subnets->all();

        /** @var string */
        $defaultIpv6 = Setting::get('default_ipv6');

        foreach ($subnets as $subnet) {
            if ($freeIp = $subnet->findFreeIp([$defaultIpv6])) {
                IpAssigned::create([
                    'user_id' => $this->user->id,
                    'ip_subnet_id' => $subnet->id,
                    'ip_address' => $freeIp,
                ]);

                return true;
            }
        }

        return false;
    }
}
