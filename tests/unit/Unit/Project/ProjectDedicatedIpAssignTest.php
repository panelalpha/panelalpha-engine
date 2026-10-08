<?php

namespace Tests\Unit\Project;

use App\Lib\Project\ProjectIpAddresses;
use App\Models\IpAssigned;
use App\Models\IpSubnet;
use App\Models\Setting;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Each dedicated-IP assignment takes an address from a non-shared subnet of
 * its own family.
 */
class ProjectDedicatedIpAssignTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings(['default_ipv4' => '192.0.2.1', 'default_ipv6' => '2001:db8::1']);
        IpSubnet::create(['ip' => '192.0.2.0', 'mask' => 29, 'family' => 4, 'is_shared' => 0]);
        IpSubnet::create(['ip' => '2001:db8::', 'mask' => 120, 'family' => 6, 'is_shared' => 0]);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_dedicated_ipv6_takes_an_ipv6_address(): void
    {
        $user = $this->makeUser('alice');

        $this->assertTrue((new ProjectIpAddresses($user))->assignFreeDedicatedIpv6());

        $this->assertSame(['2001:db8::2'], IpAssigned::query()->where('user_id', $user->id)->pluck('ip_address')->all());
    }

    public function test_a_host_without_a_default_ipv6_still_assigns_one(): void
    {
        Setting::setRuntimeSettings(['default_ipv4' => '192.0.2.1', 'default_ipv6' => '']);
        $user = $this->makeUser('alice');

        $this->assertTrue((new ProjectIpAddresses($user))->assignFreeDedicatedIpv6());

        $this->assertSame(['2001:db8::1'], IpAssigned::query()->where('user_id', $user->id)->pluck('ip_address')->all());
    }

    public function test_dedicated_ipv4_takes_an_ipv4_address(): void
    {
        $user = $this->makeUser('alice');

        $this->assertTrue((new ProjectIpAddresses($user))->assignFreeDedicatedIpv4());

        $this->assertSame(['192.0.2.2'], IpAssigned::query()->where('user_id', $user->id)->pluck('ip_address')->all());
    }
}
