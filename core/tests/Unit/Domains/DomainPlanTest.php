<?php

namespace Tests\Unit\Domains;

use App\Lib\Domains\DomainPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
    /** @var array{sites_base_domain: ?string, cert_domain: ?string, default_ipv4: ?string} */
    private const FRESH_ENGINE = [
        'sites_base_domain' => null,
        'cert_domain' => null,
        'default_ipv4' => '203.0.113.7',
    ];

    private function sources(array $candidates): array
    {
        return array_column($candidates, 'source');
    }

    public function test_a_fresh_engine_prefers_panelalpha_online_then_the_dashed_address(): void
    {
        $candidates = DomainPlan::candidates('shop', null, null, self::FRESH_ENGINE);

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
        $this->assertSame('shop.203-0-113-7.panelalpha.direct', $candidates[1]['domain']);
        $this->assertNull($candidates[1]['label']);
    }

    public function test_cert_domain_is_preferred_over_the_dashed_address(): void
    {
        $candidates = DomainPlan::candidates('shop', null, null, [
            'cert_domain' => 'engine.example.com',
            'default_ipv4' => '203.0.113.7',
        ]);

        $this->assertSame('shop.engine.example.com', $candidates[1]['domain']);
    }

    public function test_a_configured_base_domain_is_the_operators_policy_and_comes_first(): void
    {
        $candidates = DomainPlan::candidates('shop', null, null, [
            'sites_base_domain' => 'sites.example.com',
            'default_ipv4' => '203.0.113.7',
        ]);

        $this->assertSame(DomainPlan::SOURCE_SITES_BASE_DOMAIN, $candidates[0]['source']);
        $this->assertSame('shop.sites.example.com', $candidates[0]['domain']);
    }

    public function test_asking_for_a_tunnel_outranks_a_configured_base_domain(): void
    {
        $candidates = DomainPlan::candidates('shop', null, DomainPlan::TUNNEL_PANELALPHA, [
            'sites_base_domain' => 'sites.example.com',
            'default_ipv4' => '203.0.113.7',
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
        $candidates = DomainPlan::candidates('shop', null, DomainPlan::TUNNEL_NONE, self::FRESH_ENGINE);

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
            'default_ipv4' => '203.0.113.7',
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
            'default_ipv4' => '10.0.0.4',
        ]);

        $this->assertSame(
            [DomainPlan::SOURCE_PANELALPHA_DIRECT, DomainPlan::SOURCE_LOCAL],
            $this->sources($candidates)
        );
        $this->assertSame('shop.10-0-0-4.panelalpha.direct', $candidates[0]['domain']);
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
        $candidates = DomainPlan::candidates('shop', 'Shop.Example.COM.', null, self::FRESH_ENGINE);

        $this->assertSame([DomainPlan::SOURCE_REQUESTED], $this->sources($candidates));
        $this->assertSame('shop.example.com', $candidates[0]['domain'], 'normalised, never substituted');
        $this->assertNull($candidates[0]['label']);
    }

    public function test_a_requested_panelalpha_online_name_still_has_to_be_bought(): void
    {
        $candidates = DomainPlan::candidates('shop', 'acme-store.panelalpha.online', null, self::FRESH_ENGINE);

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

    /**
     * The proxy serves the exact label it registered, so the alias resolves,
     * reaches something that never heard of it, and fails TLS on the way.
     */
    public function test_a_www_alias_is_pointless_under_the_tunnel_zone(): void
    {
        $this->assertFalse(DomainPlan::wwwAliasWouldAnswer('shop-4f2a.panelalpha.online'));
        $this->assertTrue(DomainPlan::wwwAliasWouldAnswer('shop.203-0-113-7.panelalpha.direct'));
        $this->assertTrue(DomainPlan::wwwAliasWouldAnswer('shop.acme.com'));
    }

    public function test_a_host_with_no_public_address_says_so(): void
    {
        $this->assertNull(
            DomainPlan::noPublicNameReason(['default_ipv4' => '203.0.113.7']),
            'nothing to explain while a public rung was on offer'
        );

        foreach ([['default_ipv4' => '10.0.0.4'], ['default_ipv4' => null], []] as $settings) {
            $this->assertStringContainsString(
                'no public IPv4',
                (string) DomainPlan::noPublicNameReason($settings)
            );
        }

        // The two are not the same fact, and an operator acts on them
        // differently: one needs a port forwarded, the other needs an address.
        $this->assertStringContainsString(
            "address is private",
            (string) DomainPlan::noPublicNameReason(['default_ipv4' => '10.0.0.4'])
        );
        $this->assertStringContainsString(
            'no IPv4 address on record',
            (string) DomainPlan::noPublicNameReason([])
        );
    }

    public function test_only_a_public_address_counts_as_publicly_reachable(): void
    {
        $this->assertTrue(DomainPlan::hasPublicIpv4(['default_ipv4' => '203.0.113.7']));
        $this->assertFalse(DomainPlan::hasPublicIpv4(['default_ipv4' => '10.0.0.4']));
        $this->assertFalse(DomainPlan::hasPublicIpv4(['default_ipv4' => '127.0.0.1']));
        $this->assertFalse(DomainPlan::hasPublicIpv4([]));
    }

    public function test_a_retry_label_is_random_rather_than_sequential(): void
    {
        $first = DomainPlan::suffixed('shop');
        $second = DomainPlan::suffixed('shop');

        $this->assertMatchesRegularExpression('/^shop-[0-9a-f]{4}$/', $first);
        $this->assertNotSame($first, $second, 'labels are one namespace for the whole fleet');
    }
}
