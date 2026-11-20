<?php

namespace Tests\Integration;

use App\Models\Ipv4NatMap;
use App\Models\Setting;
use App\Models\User;
use Tests\Support\TestNetwork;
use Tests\TestCase;

class UserIpAddressesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::setRuntimeSettings([
            'default_ipv4' => '',
            'default_ipv6' => '',
            'disable-user-ip-assign' => '',
        ]);
        Ipv4NatMap::setLocalToPublicMap([]);
        Ipv4NatMap::setPublicToLocalMap([]);
        Ipv4NatMap::setNatModeEnabledOverride(false);
        User::setAssignedIpAddressesOverride([]);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        Ipv4NatMap::setLocalToPublicMap([]);
        Ipv4NatMap::setPublicToLocalMap([]);
        Ipv4NatMap::clearNatModeEnabledOverride();
        User::clearAssignedIpAddressesOverride();
        parent::tearDown();
    }

    public function test_get_ip_addresses_returns_default_ipv4(): void
    {
        Setting::set('default_ipv4', TestNetwork::public(50));
        $user = new User();

        $ips = $user->getIpAddresses();

        $this->assertEquals([TestNetwork::public(50)], $ips['ipv4']);
        $this->assertEmpty($ips['ipv6']);
    }

    public function test_get_bind_ip_addresses_keeps_a_public_default_ipv4(): void
    {
        Setting::set('default_ipv4', TestNetwork::public(50));
        $user = new User();

        $ips = $user->getBindIpAddresses();

        $this->assertEquals([TestNetwork::public(50)], $ips['ipv4']);
    }

    public function test_get_bind_ip_addresses_keeps_local_default_ipv4(): void
    {
        Setting::set('default_ipv4', TestNetwork::private(5));
        $user = new User();

        $ips = $user->getBindIpAddresses();

        $this->assertEquals([TestNetwork::private(5)], $ips['ipv4']);
    }

    public function test_nat_mode_maps_local_to_public_for_clients(): void
    {
        Setting::set('default_ipv4', TestNetwork::private(5));
        Ipv4NatMap::setNatModeEnabledOverride(true);
        Ipv4NatMap::setLocalToPublicMap([TestNetwork::private(5) => TestNetwork::public(50)]);
        $user = new User();

        $ips = $user->getIpAddresses();

        $this->assertEquals([TestNetwork::public(50)], $ips['ipv4']);
    }

    public function test_nat_mode_maps_public_to_local_for_binding(): void
    {
        Setting::set('default_ipv4', TestNetwork::public(50));
        Ipv4NatMap::setNatModeEnabledOverride(true);
        Ipv4NatMap::setPublicToLocalMap([TestNetwork::public(50) => TestNetwork::private(5)]);
        $user = new User();

        $ips = $user->getBindIpAddresses();

        $this->assertEquals([TestNetwork::private(5)], $ips['ipv4']);
    }
}
