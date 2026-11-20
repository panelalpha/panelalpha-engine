<?php

namespace Tests\Integration\Project;

use App\Lib\Project\ProjectIpAddresses;
use App\Models\IpAssigned;
use App\Models\IpSubnet;
use App\Models\Ipv4NatMap;
use App\Models\Setting;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * A project's own dedicated addresses take the place of the engine's defaults family by family, and an assignment
 * that finds no free address in any subnet reports so.
 */
class ProjectIpAddressesTest extends TestCase
{
    use InMemoryDatabase;

    private const IPV6 = '2001:db8::7';

    private const DEFAULT_IPV6 = '2001:db8::1';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setRuntimeSettings([
            'default_ipv4' => TestNetwork::public(1),
            'default_ipv6' => self::DEFAULT_IPV6,
            'disable-user-ip-assign' => '',
        ]);
        Ipv4NatMap::setNatModeEnabledOverride(false);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        Ipv4NatMap::clearNatModeEnabledOverride();
        User::clearAssignedIpAddressesOverride();
        parent::tearDown();
    }

    public function test_dedicated_flags_are_read_only_as_booleans(): void
    {
        $this->assertTrue((new ProjectIpAddresses(new User(['details' => ['dedicated_ipv4' => true, 'dedicated_ipv6' => true]])))->dedicatedIpv4Enabled());
        $this->assertTrue((new ProjectIpAddresses(new User(['details' => ['dedicated_ipv4' => true, 'dedicated_ipv6' => true]])))->dedicatedIpv6Enabled());
        $this->assertFalse((new ProjectIpAddresses(new User(['details' => ['dedicated_ipv4' => 'yes', 'dedicated_ipv6' => 1]])))->dedicatedIpv4Enabled());
        $this->assertFalse((new ProjectIpAddresses(new User(['details' => ['dedicated_ipv4' => 'yes', 'dedicated_ipv6' => 1]])))->dedicatedIpv6Enabled());
    }

    public function test_assigned_addresses_replace_the_defaults_of_their_own_family(): void
    {
        User::setAssignedIpAddressesOverride([new IpAssigned(['ip_address' => self::IPV6])]);

        $ips = (new ProjectIpAddresses(new User))->getIpAddresses();

        $this->assertSame(['ipv4' => [TestNetwork::public(1)], 'ipv6' => [self::IPV6]], $ips);
    }

    public function test_assigned_ipv4_addresses_are_bound_alongside_the_default_ipv6(): void
    {
        User::setAssignedIpAddressesOverride([new IpAssigned(['ip_address' => TestNetwork::public(7)]), new IpAssigned(['ip_address' => TestNetwork::public(8)])]);

        $ips = (new ProjectIpAddresses(new User))->getBindIpAddresses();

        $this->assertSame(['ipv4' => [TestNetwork::public(7), TestNetwork::public(8)], 'ipv6' => [self::DEFAULT_IPV6]], $ips);
    }

    public function test_an_engine_that_assigns_no_addresses_declares_none_by_default(): void
    {
        Setting::setRuntimeSettings(['disable-user-ip-assign' => '1', 'default_ipv4' => TestNetwork::public(1)]);

        $this->assertSame(['ipv4' => [], 'ipv6' => []], ProjectIpAddresses::defaultIpAddresses());
    }

    public function test_no_free_address_in_any_subnet_assigns_nothing(): void
    {
        $this->bootInMemoryDatabase();
        $user = $this->makeUser('alice');
        $taken = $this->makeUser('bob');
        $v4 = IpSubnet::create(['ip' => TestNetwork::public3(0), 'mask' => 31, 'family' => 4, 'is_shared' => 0]);
        $v6 = IpSubnet::create(['ip' => '2001:db8::', 'mask' => 127, 'family' => 6, 'is_shared' => 0]);
        foreach ([[$v4, TestNetwork::public3(0)], [$v4, TestNetwork::public3(1)], [$v6, '2001:db8::'], [$v6, '2001:db8::1']] as [$subnet, $ip]) {
            IpAssigned::create(['user_id' => $taken->id, 'ip_subnet_id' => $subnet->id, 'ip_address' => $ip]);
        }
        $addresses = new ProjectIpAddresses($user);

        $this->assertFalse($addresses->assignFreeDedicatedIpv4());
        $this->assertFalse($addresses->assignFreeDedicatedIpv6());
        $this->assertSame(0, IpAssigned::query()->where('user_id', $user->id)->count());
    }
}
