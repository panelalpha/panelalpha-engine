<?php

namespace Tests\Unit\Domains;

use App\Lib\Domains\NamingZones;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** Which parents of a project's names a cookie must not be written for. */
class NamingZonesTest extends TestCase
{
    private static function dashed(string $base): string
    {
        return str_replace('.', '-', TestNetwork::PRIVATE_10).'.'.$base;
    }

    public function test_zones_are_normalised_and_a_zone_inside_another_is_dropped(): void
    {
        $zones = NamingZones::of([
            'PanelAlpha.Online.',
            'panelalpha.direct',
            self::dashed('panelalpha.direct'),
            'sites.example.com',
            '',
            'com',
            TestNetwork::PRIVATE_10,
        ]);

        $this->assertSame(['panelalpha.online', 'panelalpha.direct', 'sites.example.com'], $zones->all());
    }

    public function test_a_zone_covers_itself_and_the_names_below_it(): void
    {
        $zones = NamingZones::of(['panelalpha.online']);

        $this->assertTrue($zones->covers('panelalpha.online'));
        $this->assertTrue($zones->covers('a.go.panelalpha.online'));
        $this->assertFalse($zones->covers('notpanelalpha.online'));
        $this->assertFalse($zones->covers('online'));
    }

    public function test_a_panelalpha_online_name_shares_the_zone(): void
    {
        $zones = NamingZones::of(['panelalpha.online']);

        $this->assertSame(['panelalpha.online'], $zones->sharedParentsOf(['shop-4f2a.panelalpha.online'], ['shop-4f2a.panelalpha.online']));
    }

    public function test_a_go_preview_shares_go_and_the_zone(): void
    {
        $zones = NamingZones::of(['panelalpha.online']);

        $this->assertSame(
            ['go.panelalpha.online', 'panelalpha.online'],
            $zones->sharedParentsOf(['a.go.panelalpha.online'], ['a.go.panelalpha.online'])
        );
    }

    public function test_a_name_on_the_per_address_direct_base_shares_both_parents(): void
    {
        $zones = NamingZones::of(['panelalpha.direct', self::dashed('panelalpha.direct')]);

        $this->assertSame(
            [self::dashed('panelalpha.direct'), 'panelalpha.direct'],
            $zones->sharedParentsOf(['shop.'.self::dashed('panelalpha.direct')], [])
        );
    }

    public function test_the_example_base_under_sslip_io_shares_every_sslip_io_name(): void
    {
        $zones = NamingZones::of(['sslip.io', self::dashed('sslip.io')]);

        $this->assertSame(['sslip.io'], $zones->all());
        $this->assertSame([self::dashed('sslip.io'), 'sslip.io'], $zones->sharedParentsOf(['shop.'.self::dashed('sslip.io')], []));
    }

    public function test_a_custom_domain_beside_the_sites_base_shares_its_parent(): void
    {
        $zones = NamingZones::of(['sites.example.com']);

        $this->assertSame(['example.com'], $zones->sharedParentsOf(['shop.example.com'], ['shop.example.com']));
        $this->assertSame(['sites.example.com', 'example.com'], $zones->sharedParentsOf(['a.sites.example.com'], []));
    }

    public function test_an_unrelated_custom_domain_keeps_its_parent(): void
    {
        $zones = NamingZones::of(['panelalpha.online', 'sites.example.com']);

        $this->assertSame([], $zones->sharedParentsOf(['www.shop.com', 'shop.com'], ['www.shop.com', 'shop.com']));
        $this->assertSame([], $zones->sharedParentsOf(['www.shop.co.uk'], ['www.shop.co.uk']));
    }

    public function test_a_parent_the_project_owns_is_not_shared(): void
    {
        $zones = NamingZones::of(['sites.example.com']);

        $this->assertSame(
            ['sites.example.com', 'example.com'],
            $zones->sharedParentsOf(['www.shop.sites.example.com'], ['shop.sites.example.com', 'www.shop.sites.example.com'])
        );
    }

    public function test_only_hostnames_normalise(): void
    {
        $this->assertSame('shop.example.com', NamingZones::normalise(' Shop.Example.COM. '));
        $this->assertNull(NamingZones::normalise('*.example.com'));
        $this->assertNull(NamingZones::normalise(TestNetwork::PRIVATE_10));
        $this->assertNull(NamingZones::normalise('localhost'));
        $this->assertNull(NamingZones::normalise('a"b.example.com'));
    }
}
