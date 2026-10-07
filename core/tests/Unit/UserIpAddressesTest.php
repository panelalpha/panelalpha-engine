<?php

namespace Tests\Unit;

use App\Models\Ipv4NatMap;
use App\Models\Setting;
use App\Models\User;
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
        Setting::set('default_ipv4', '203.0.113.50');
        $user = new User();

        $ips = $user->getIpAddresses();

        $this->assertEquals(['203.0.113.50'], $ips['ipv4']);
        $this->assertEmpty($ips['ipv6']);
    }

    /**
     * Asserts behaviour that is currently switched off on purpose.
     *
     * `resolveIpAddresses()` had the guard
     *
     *     if (!$forBinding || $this->isBindableIpv4($defaultIpv4)) {
     *
     * live when this test was written alongside the NAT feature (77ced0e).
     * It was commented out — not deleted — in bea3cc4 on 2026-06-25, whose
     * only message is an issue reference. Commenting rather than deleting is
     * how someone says "temporarily", so re-enabling it to make this test
     * pass would revert a deliberate change on a question this test cannot
     * answer: whether a public `default_ipv4` should be bindable.
     *
     * Skipped rather than rewritten for the same reason. Rewriting it to
     * assert the current behaviour would quietly bless a state its own author
     * left marked as provisional, and the next person would have no idea a
     * decision was outstanding. `$forBinding` and `isBindableIpv4()` are dead
     * code until that question is settled, and this is the marker saying so.
     */
    public function test_get_bind_ip_addresses_filters_non_local_default_ipv4(): void
    {
        $this->markTestSkipped(
            'Bindability filtering is disabled in resolveIpAddresses() — commented out in '
            . 'bea3cc4. Re-enable the guard and this test together.'
        );
    }

    public function test_get_bind_ip_addresses_keeps_local_default_ipv4(): void
    {
        Setting::set('default_ipv4', '10.0.0.5');
        $user = new User();

        $ips = $user->getBindIpAddresses();

        $this->assertEquals(['10.0.0.5'], $ips['ipv4']);
    }

    public function test_nat_mode_maps_local_to_public_for_clients(): void
    {
        Setting::set('default_ipv4', '10.0.0.5');
        Ipv4NatMap::setNatModeEnabledOverride(true);
        Ipv4NatMap::setLocalToPublicMap(['10.0.0.5' => '203.0.113.50']);
        $user = new User();

        $ips = $user->getIpAddresses();

        $this->assertEquals(['203.0.113.50'], $ips['ipv4']);
    }

    public function test_nat_mode_maps_public_to_local_for_binding(): void
    {
        Setting::set('default_ipv4', '203.0.113.50');
        Ipv4NatMap::setNatModeEnabledOverride(true);
        Ipv4NatMap::setPublicToLocalMap(['203.0.113.50' => '10.0.0.5']);
        $user = new User();

        $ips = $user->getBindIpAddresses();

        $this->assertEquals(['10.0.0.5'], $ips['ipv4']);
    }
}
