<?php

namespace App\Lib\Project;

use App\Models\IpAssigned;
use App\Models\IpSubnet;
use App\Models\Ipv4NatMap;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The addresses a project answers on: its own dedicated IPs if it has any,
 * else the engine's defaults, translated through the NAT map when NAT mode
 * is on. Moved out of User as it was, dead branches included -- see
 * {@see \Tests\Unit\UserIpAddressesTest} for why they stay.
 */
final class ProjectIpAddresses
{
    public function __construct(private readonly User $user)
    {
    }

    public function dedicatedIpv4Enabled(): bool
    {
        $default = false;
        $details = $this->user->getDetails();
        if (empty($details['dedicated_ipv4']) || !is_bool($details['dedicated_ipv4'])) {
            return $default;
        }
        return $details['dedicated_ipv4'];
    }

    public function dedicatedIpv6Enabled(): bool
    {
        $default = false;
        $details = $this->user->getDetails();
        if (empty($details['dedicated_ipv6']) || !is_bool($details['dedicated_ipv6'])) {
            return $default;
        }
        return $details['dedicated_ipv6'];
    }

    /**
     * @return array{
     *   ipv4: list<string>,
     *   ipv6: list<string>,
     * }
     */
    public function getIpAddresses(): array
    {
        $ips = $this->resolveIpAddresses(false);

        // When NAT mode is active, map local IPs to their public counterparts
        // so clients and DNS see the public address.
        if (Ipv4NatMap::isNatModeEnabled()) {
            $localToPublic = Ipv4NatMap::getLocalToPublicMap();
            $ips['ipv4'] = self::mapIps($ips['ipv4'], $localToPublic);
        }

        return $ips;
    }

    /**
     * Returns the IPs that should be used for webserver bind/listen directives.
     * Under NAT mode public IPs are translated back to local IPs. When no NAT
     * maps exist, non-local default IPv4 addresses are filtered out to prevent
     * webserver bind failures on cloud VMs.
     *
     * @return array{
     *   ipv4: array<string>,
     *   ipv6: array<string>,
     * }
     */
    public function getBindIpAddresses(): array
    {
        $ips = $this->resolveIpAddresses(true);

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
    private function resolveIpAddresses(bool $forBinding): array
    {
        $ips = [
            'ipv4' => [],
            'ipv6' => [],
        ];

        if (!empty(Setting::get('disable-user-ip-assign'))) {
            return $ips;
        }

        $assigned = $this->user->getAssignedIpAddresses();
        if (!empty($assigned)) {
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
     * The engine's own addresses: what a user with no dedicated IP resolves to.
     *
     * Static and user-independent on purpose. The webserver's main config has
     * to declare these listen addresses from install time, and there are no
     * users then -- deriving them from the user table instead makes them
     * appear only once somebody is hosted, which is a bind address nginx
     * cannot adopt on a reload. {@see AbstractWebserver::getAllIpsVars()}.
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

        if (!empty(Setting::get('disable-user-ip-assign'))) {
            return $ips;
        }

        $defaultIpv4 = Setting::get('default_ipv4');
        if (!empty($defaultIpv4)) {
            $ips['ipv4'][] = $defaultIpv4;
        }

        $defaultIpv6 = Setting::get('default_ipv6');
        if (!empty($defaultIpv6)) {
            $ips['ipv6'][] = $defaultIpv6;
        }

        return $ips;
    }

    /**
     * {@see defaultIpAddresses()} translated for binding, the way
     * {@see getBindIpAddresses()} translates a user's own addresses.
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
     * @param array<string> $ips
     * @param array<string, string> $map
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

    private function isBindableIpv4(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        // NAT mode handles public IPs via the mapping table.
        if (Ipv4NatMap::isNatModeEnabled()) {
            return true;
        }

        return $this->isLocalIpv4($ip);
    }

    private function isLocalIpv4(string $ip): bool
    {
        // 127.0.0.0/8 is local but not usable as a default bind address.
        if (strpos($ip, '127.') === 0) {
            return false;
        }

        // 0.0.0.0/8 is invalid.
        if (strpos($ip, '0.') === 0) {
            return false;
        }

        // RFC 1918 private ranges and RFC 6598 CGNAT range are local bindable.
        $privateRanges = [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '100.64.0.0/10',
        ];

        foreach ($privateRanges as $range) {
            if (\Symfony\Component\HttpFoundation\IpUtils::checkIp($ip, $range)) {
                return true;
            }
        }

        return false;
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
            ->where('family', 4)
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
