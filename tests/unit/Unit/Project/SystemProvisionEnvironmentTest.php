<?php

namespace Tests\Unit\Project;

use App\Lib\Project\SystemProvisionEnvironment;
use App\Models\IpSubnet;
use App\Models\Setting;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A free dedicated address is looked for per family, in non-shared subnets only.
 */
class SystemProvisionEnvironmentTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings(['default_ipv4' => '192.0.2.1', 'default_ipv6' => '']);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_host_with_no_subnets_has_no_free_dedicated_ip(): void
    {
        $env = new SystemProvisionEnvironment();

        $this->assertFalse($env->freeDedicatedIpExists(4));
        $this->assertFalse($env->freeDedicatedIpExists(6));
    }

    public function test_a_shared_subnet_does_not_count(): void
    {
        IpSubnet::create(['ip' => '192.0.2.0', 'mask' => 29, 'family' => 4, 'is_shared' => 1]);

        $this->assertFalse((new SystemProvisionEnvironment())->freeDedicatedIpExists(4));
    }

    public function test_each_family_is_looked_for_in_its_own_subnets(): void
    {
        IpSubnet::create(['ip' => '2001:db8::', 'mask' => 120, 'family' => 6, 'is_shared' => 0]);
        $env = new SystemProvisionEnvironment();

        $this->assertFalse($env->freeDedicatedIpExists(4));
        $this->assertTrue($env->freeDedicatedIpExists(6));
    }
}
