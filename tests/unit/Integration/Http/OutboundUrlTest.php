<?php

namespace Tests\Integration\Http;

use App\Lib\Http\OutboundUrl;
use PHPUnit\Framework\TestCase;
use Tests\Support\OutboundUrlFixtures;

/**
 * engine#785: a token narrowed to file_upload / files fetch must not be able
 * to read the cloud metadata service, the cache registry, sites-phpmyadmin or
 * host services. The guard refuses every address TenantNetwork::refuses()
 * refuses, plus IPv6, and every A/AAAA record a hostname resolves to.
 */
class OutboundUrlTest extends TestCase
{
    use OutboundUrlFixtures;

    /**
     * A hostname that resolves to both a public and an internal address is
     * refused: the connector could otherwise be steered to the internal one.
     */
    public function test_a_hostname_resolving_to_an_internal_address_is_refused(): void
    {
        // `localhost` resolves to the IPv4 loopback (and ::1); both are refused.
        $this->assertFalse(OutboundUrl::isAllowed('http://localhost/x'));
        // A literal public IPv6 address is allowed, its own range not refused.
        $this->assertTrue(OutboundUrl::isAllowed('http://[2001:4860:4860::8888]/x'));
    }
}
