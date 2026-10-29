<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Models\IpSubnet;
use App\Models\Ipv4NatMap;
use App\Models\Setting;

/**
 * This Engine's own addresses and subnets: the default IPv4 and IPv6, both
 * sides of every NAT map, and every managed IP subnet.
 */
class EngineAddresses
{
    /**
     * @return list<string> addresses and CIDR ranges
     */
    public function ranges(): array
    {
        $ranges = [Setting::get('default_ipv4'), Setting::get('default_ipv6')];
        foreach (Ipv4NatMap::getLocalToPublicMap() as $local => $public) {
            $ranges[] = $local;
            $ranges[] = $public;
        }
        foreach (IpSubnet::query()->get() as $subnet) {
            $ranges[] = $subnet->getCidr();
        }

        return array_values(array_unique(array_filter(
            $ranges,
            static fn (mixed $range): bool => is_string($range) && $range !== '',
        )));
    }
}
