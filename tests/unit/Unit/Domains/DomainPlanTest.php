<?php

namespace Tests\Unit\Domains;

use App\Lib\Domains\DomainPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * The ladder, without a database or a network.
 *
 * What is checked here is the *order* and what each rung is called, because
 * that is the part a caller depends on: a project that comes back with
 * source `panelalpha_direct` is telling the caller its address is self-signed,
 * and a project on `local` is telling it nobody can open the site at all.
 */
class DomainPlanTest extends TestCase
{
    /** @return array{sites_base_domain: ?string, cert_domain: ?string, default_ipv4: ?string} */
    private static function freshEngine(): array
    {
        return [
            'sites_base_domain' => null,
            'cert_domain' => null,
            'default_ipv4' => TestNetwork::public(7),
        ];
    }

    private function sources(array $candidates): array
    {
        return array_column($candidates, 'source');
    }

    public function test_a_fresh_engine_prefers_panelalpha_online_then_the_dashed_address(): void
    {
        $candidates = DomainPlan::candidates('shop', null, null, self::freshEngine());

        $this->assertSame(
            [DomainPlan::SOURCE_PANELALPHA_ONLINE, DomainPlan::SOURCE_PANELALPHA_DIRECT, DomainPlan::SOURCE_LOCAL],
            $this->sources($candidates)
        );
        $this->assertMatchesRegularExpression(
            '/^shop-[0-9a-f]{4}\\.panelalpha\\.online$/',
            $candidates[0]['domain'],
            'suffixed from the first attempt: the proxy does not enforce uniqueness'
        );
        $this->assertSame(
            substr($candidates[0]['domain'], 0, -strlen('.panelalpha.online')),
            $candidates[0]['label'],
            'the online rung is the one that has to be bought'
        );
        $this->assertSame('shop.'.str_replace('.', '-', TestNetwork::public(7)).'.panelalpha.direct', $candidates[1]['domain']);
        $this->assertNull($candidates[1]['label']);
    }

    public function test_cert_domain_is_preferred_over_the_dashed_address(): void
    {
        $candidates = DomainPlan::candidates('shop', null, null, [
            'cert_domain' => 'engine.example.com',
            'default_ipv4' => TestNetwork::public(7),
        ]);

        $this->assertSame('shop.engine.example.com', $candidates[1]['domain']);
    }

    public function test_a_configured_base_domain_is_the_operators_policy_and_comes_first(): void
    {
        $candidates = DomainPlan::candidates('shop', null, null, [
            'sites_base_domain' => 'sites.example.com',
            'default_ipv4' => TestNetwork::public(7),
        ]);

        $this->assertSame(DomainPlan::SOURCE_SITES_BASE_DOMAIN, $candidates[0]['source']);
        $this->assertSame('shop.sites.example.com', $candidates[0]['domain']);
    }

    public function test_asking_for_a_tunnel_outranks_a_configured_base_domain(): void
    {
        $candidates = DomainPlan::candidates('shop', null, DomainPlan::TUNNEL_PANELALPHA, [
            'sites_base_domain' => 'sites.example.com',
            'default_ipv4' => TestNetwork::public(7),
        ]);

        $this->assertSame(DomainPlan::SOURCE_PANELALPHA_ONLINE, $candidates[0]['source']);
        $this->assertContains(
            DomainPlan::SOURCE_SITES_BASE_DOMAIN,
            $this->sources($candidates),
            'and is still a better last resort than .local'
        );
    }

    public function test_tunnel_none_never_offers_a_name_that_has_to_be_bought(): void
    {
        $candidates = DomainPlan::candidates('shop', null, DomainPlan::TUNNEL_NONE, self::freshEngine());

        $this->assertNotContains(DomainPlan::SOURCE_PANELALPHA_ONLINE, $this->sources($candidates));
        $this->assertSame([null, null], array_column($candidates, 'label'));
    }

    /**
     * The proxy answers an unlicensed call -- measured, 200 with no token --
     * so a license key is not a precondition for the rung. An engine it will
     * not serve learns that by asking.
     */
    public function test_an_unlicensed_engine_is_still_offered_the_zone(): void
    {
        $candidates = DomainPlan::candidates('shop', null, null, [
            'default_ipv4' => TestNetwork::public(7),
        ]);

        $this->assertSame(DomainPlan::SOURCE_PANELALPHA_ONLINE, $this->sources($candidates)[0]);
    }

    /**
     * The zone is DNS and nothing else: it resolves whatever address the label
     * spells, at any depth and private ranges included. So a host on a LAN
     * gets a name every other machine on that LAN can open, where `.local`
     * would have answered for nobody -- the engine registers no mDNS.
     * panelalpha.online is the rung a private address really does lose, since
     * the proxy has to reach the host from the internet.
     */
    public function test_a_private_address_still_gets_a_direct_name(): void
    {
        $candidates = DomainPlan::candidates('shop', null, null, [
            'default_ipv4' => TestNetwork::private(4),
        ]);

        $this->assertSame(
            [DomainPlan::SOURCE_PANELALPHA_DIRECT, DomainPlan::SOURCE_LOCAL],
            $this->sources($candidates)
        );
        $this->assertSame('shop.'.str_replace('.', '-', TestNetwork::private(4)).'.panelalpha.direct', $candidates[0]['domain']);
        $this->assertSame('shop.local', $candidates[1]['domain']);
    }

    public function test_a_host_with_no_address_at_all_falls_to_local(): void
    {
        foreach ([['default_ipv4' => null], ['default_ipv4' => 'not-an-address'], []] as $settings) {
            $candidates = DomainPlan::candidates('shop', null, null, $settings);

            $this->assertSame([DomainPlan::SOURCE_LOCAL], $this->sources($candidates));
            $this->assertSame('shop.local', $candidates[0]['domain']);
        }
    }

    public function test_a_requested_domain_is_the_only_candidate(): void
    {
        $candidates = DomainPlan::candidates('shop', 'Shop.Example.COM.', null, self::freshEngine());

        $this->assertSame([DomainPlan::SOURCE_REQUESTED], $this->sources($candidates));
        $this->assertSame('shop.example.com', $candidates[0]['domain'], 'normalised, never substituted');
        $this->assertNull($candidates[0]['label']);
    }

    public function test_a_requested_panelalpha_online_name_still_has_to_be_bought(): void
    {
        $candidates = DomainPlan::candidates('shop', 'acme-store.panelalpha.online', null, self::freshEngine());

        $this->assertSame([DomainPlan::SOURCE_REQUESTED], $this->sources($candidates));
        $this->assertSame('acme-store', $candidates[0]['label']);
    }

    #[DataProvider('labels')]
    public function test_online_label_recognises_only_a_single_label_under_the_zone(?string $expected, string $domain): void
    {
        $this->assertSame($expected, DomainPlan::onlineLabel($domain));
    }

    public static function labels(): array
    {
        return [
            'plain' => ['shop', 'shop.panelalpha.online'],
            'uppercase and trailing dot' => ['shop', 'SHOP.panelalpha.online.'],
            'hyphenated' => ['shop-4f2a', 'shop-4f2a.panelalpha.online'],
            'two labels deep' => [null, 'a.b.panelalpha.online'],
            'another zone' => [null, 'shop.example.com'],
            'the zone itself' => [null, 'panelalpha.online'],
            'leading hyphen' => [null, '-shop.panelalpha.online'],
        ];
    }

    public function test_the_online_zone_holds_a_name_at_any_depth_below_it(): void
    {
        foreach (['shop.panelalpha.online', 'SHOP.PanelAlpha.Online.', 'a.b.panelalpha.online'] as $inside) {
            $this->assertTrue(DomainPlan::inOnlineZone($inside), $inside);
        }
        foreach (['panelalpha.online', 'shop.example.com', 'shoppanelalpha.online'] as $outside) {
            $this->assertFalse(DomainPlan::inOnlineZone($outside), $outside);
        }
    }

    /**
     * The proxy serves the exact label it registered, so the alias resolves,
     * reaches something that never heard of it, and fails TLS on the way.
     */
    public function test_a_www_alias_is_pointless_under_the_tunnel_zone(): void
    {
        $this->assertFalse(DomainPlan::wwwAliasWouldAnswer('shop-4f2a.panelalpha.online'));
        $this->assertTrue(DomainPlan::wwwAliasWouldAnswer('shop.'.str_replace('.', '-', TestNetwork::public(7)).'.panelalpha.direct'));
        $this->assertTrue(DomainPlan::wwwAliasWouldAnswer('shop.acme.com'));
    }

    public function test_a_host_with_no_public_address_says_so(): void
    {
        $this->assertNull(
            DomainPlan::noPublicNameReason(['default_ipv4' => TestNetwork::public(7)]),
            'nothing to explain while a public rung was on offer'
        );

        foreach ([['default_ipv4' => TestNetwork::private(4)], ['default_ipv4' => null], []] as $settings) {
            $this->assertStringContainsString(
                'no public IPv4',
                (string) DomainPlan::noPublicNameReason($settings)
            );
        }

        // The two are not the same fact, and an operator acts on them
        // differently: one needs a port forwarded, the other needs an address.
        $this->assertStringContainsString(
            "address is private",
            (string) DomainPlan::noPublicNameReason(['default_ipv4' => TestNetwork::private(4)])
        );
        $this->assertStringContainsString(
            'no IPv4 address on record',
            (string) DomainPlan::noPublicNameReason([])
        );
    }

    public function test_only_a_public_address_counts_as_publicly_reachable(): void
    {
        $this->assertTrue(DomainPlan::hasPublicIpv4(['default_ipv4' => TestNetwork::public(7)]));
        $this->assertFalse(DomainPlan::hasPublicIpv4(['default_ipv4' => TestNetwork::private(4)]));
        $this->assertFalse(DomainPlan::hasPublicIpv4(['default_ipv4' => TestNetwork::LOOPBACK]));
        $this->assertFalse(DomainPlan::hasPublicIpv4([]));
    }

    public function test_a_retry_label_is_random_rather_than_sequential(): void
    {
        $first = DomainPlan::suffixed('shop');
        $second = DomainPlan::suffixed('shop');

        $this->assertMatchesRegularExpression('/^shop-[0-9a-f]{4}$/', $first);
        $this->assertNotSame($first, $second, 'labels are one namespace for the whole fleet');
    }

    public function test_default_tunnel_none_takes_the_online_rung_away(): void
    {
        $candidates = DomainPlan::candidates('shop', null, null, [
            'default_tunnel' => DomainPlan::TUNNEL_NONE,
            'cert_domain' => 'engine.example.com',
            'default_ipv4' => TestNetwork::public(7),
        ]);

        $this->assertSame(
            [DomainPlan::SOURCE_PANELALPHA_DIRECT, DomainPlan::SOURCE_LOCAL],
            $this->sources($candidates)
        );
        $this->assertSame('shop.engine.example.com', $candidates[0]['domain']);
        $this->assertSame([null, null], array_column($candidates, 'label'));
    }

    public function test_default_tunnel_none_leaves_a_configured_base_domain_first(): void
    {
        $candidates = DomainPlan::candidates('shop', null, null, [
            'default_tunnel' => DomainPlan::TUNNEL_NONE,
            'sites_base_domain' => 'sites.example.com',
            'default_ipv4' => TestNetwork::public(7),
        ]);

        $this->assertSame(DomainPlan::SOURCE_SITES_BASE_DOMAIN, $candidates[0]['source']);
        $this->assertNotContains(DomainPlan::SOURCE_PANELALPHA_ONLINE, $this->sources($candidates));
    }

    /**
     * The setting is a default, not a veto: a caller that names panelalpha gets
     * the online rung even while `default_tunnel` says none.
     */
    public function test_an_explicit_tunnel_outranks_the_default_setting(): void
    {
        $settings = [
            'default_tunnel' => DomainPlan::TUNNEL_NONE,
            'sites_base_domain' => 'sites.example.com',
            'default_ipv4' => TestNetwork::public(7),
        ];

        $explicit = DomainPlan::candidates('shop', null, DomainPlan::TUNNEL_PANELALPHA, $settings);

        $this->assertSame(DomainPlan::SOURCE_PANELALPHA_ONLINE, $explicit[0]['source']);
        $this->assertNotNull($explicit[0]['label'], 'a panelalpha tunnel has to be bought');
    }

    /**
     * `panelalpha` is the default written down, not an explicit request: with a base
     * domain configured it still loses to it, exactly as an unset setting does.
     */
    public function test_default_tunnel_panelalpha_keeps_the_same_ladder_as_unset(): void
    {
        $configured = ['sites_base_domain' => 'sites.example.com', 'default_ipv4' => TestNetwork::public(7)];

        $unset = DomainPlan::candidates('shop', null, null, $configured);
        $setting = DomainPlan::candidates('shop', null, null, $configured + ['default_tunnel' => DomainPlan::TUNNEL_PANELALPHA]);
        $explicit = DomainPlan::candidates('shop', null, DomainPlan::TUNNEL_PANELALPHA, $configured);

        $this->assertSame($this->sources($unset), $this->sources($setting));
        $this->assertSame(DomainPlan::SOURCE_SITES_BASE_DOMAIN, $setting[0]['source']);
        $this->assertNotSame(
            $this->sources($explicit),
            $this->sources($setting),
            'the setting is not an explicit panelalpha; only the request can be'
        );
    }

    /**
     * An unset setting is not the same request as an explicit panelalpha: with a
     * base domain configured, the operator's domain wins. This is the behaviour a
     * fresh engine has always had, and it must not change when the setting is absent.
     */
    public function test_an_unset_setting_keeps_the_ladder_as_it_was(): void
    {
        $configured = ['sites_base_domain' => 'sites.example.com', 'default_ipv4' => TestNetwork::public(7)];

        $before = DomainPlan::candidates('shop', null, null, $configured);
        $withNullKey = DomainPlan::candidates('shop', null, null, $configured + ['default_tunnel' => null]);

        $this->assertSame($this->sources($before), $this->sources($withNullKey));
        $this->assertSame(DomainPlan::SOURCE_SITES_BASE_DOMAIN, $before[0]['source']);
    }

    public function test_an_unknown_setting_value_reads_as_unset(): void
    {
        $candidates = DomainPlan::candidates('shop', null, null, [
            'default_tunnel' => 'cloudflare',
            'default_ipv4' => TestNetwork::public(7),
        ]);

        $this->assertSame(DomainPlan::SOURCE_PANELALPHA_ONLINE, $candidates[0]['source']);
    }
}
