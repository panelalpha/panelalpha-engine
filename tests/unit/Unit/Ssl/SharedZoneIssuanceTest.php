<?php

namespace Tests\Unit\Ssl;

use App\Lib\Ssl\SharedZones;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who may spend the fleet's weekly allowance, and who is told they may not.
 */
class SharedZoneIssuanceTest extends TestCase
{
    public function test_a_customers_own_domain_is_never_gated(): void
    {
        $this->assertNull(SharedZones::ineligibleReason('shop.acme.com', false));
        $this->assertNull(SharedZones::ineligibleReason('shop.acme.com', true));
    }

    public function test_a_shared_zone_name_is_refused_until_the_operator_opts_in(): void
    {
        $reason = SharedZones::ineligibleReason('shop.203-0-113-7.panelalpha.direct', false);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('panelalpha.direct', $reason);
        $this->assertStringContainsString('shared_zone_issuance', $reason);

        $this->assertNull(SharedZones::ineligibleReason('shop.203-0-113-7.panelalpha.direct', true));
    }

    /**
     * A name no authority would issue for is refused whatever the operator
     * decided -- the setting buys a rate limit, not a miracle.
     */
    #[DataProvider('impossibleNames')]
    public function test_an_unissuable_name_is_refused_either_way(string $domain): void
    {
        foreach ([true, false] as $allowed) {
            $reason = SharedZones::ineligibleReason($domain, $allowed);
            $this->assertNotNull($reason, "{$domain} allowed={$allowed}");
            $this->assertStringContainsString('not a public hostname', $reason);
        }
    }

    public static function impossibleNames(): array
    {
        return [
            'reserved tld' => ['shop.local'],
            'bare hostname' => ['shop'],
            'an address' => ['203.0.113.7'],
        ];
    }

    #[DataProvider('zones')]
    public function test_the_message_names_the_zone_it_is_about(?string $expected, string $domain): void
    {
        $this->assertSame($expected, SharedZones::zoneOf($domain));
    }

    public static function zones(): array
    {
        return [
            ['panelalpha.direct', 'shop.203-0-113-7.panelalpha.direct'],
            ['nip.io', 'shop.1-2-3-4.nip.io'],
            ['sslip.io', 'SHOP.1-2-3-4.SSLIP.IO.'],
            ['panelalpha.online', 'shop-4f2a.panelalpha.online'],
            [null, 'shop.acme.com'],
        ];
    }
}
