<?php

namespace Tests\Unit\Cloudflare;

use App\Integrations\Tunnels\HostProxyOrigin;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/** A connector hairpinning through the host proxy has to reach an address the vhost listens on. */
class HostProxyOriginTest extends TestCase
{
    public function test_a_wildcard_rule_reaches_the_accounts_first_address(): void
    {
        $first = TestNetwork::private(44);
        $ips = ['ipv4' => [$first, TestNetwork::private(50)], 'ipv6' => ['2001:db8::1']];

        $this->assertSame("https://{$first}:443", HostProxyOrigin::url(true, '*', $ips));
        $this->assertSame("http://{$first}:80", HostProxyOrigin::url(false, null, $ips));
    }

    public function test_a_rule_bound_to_one_address_is_reached_there(): void
    {
        $bound = TestNetwork::private(50);
        $ips = ['ipv4' => [TestNetwork::private(44)], 'ipv6' => []];

        $this->assertSame("https://{$bound}:443", HostProxyOrigin::url(true, $bound, $ips));
        $this->assertSame('http://[2001:db8::2]:80', HostProxyOrigin::url(false, '2001:db8::2', $ips));
    }

    public function test_an_ipv6_only_account_gets_a_bracketed_address(): void
    {
        $this->assertSame('https://[2001:db8::1]:443', HostProxyOrigin::url(true, '*', ['ipv4' => [], 'ipv6' => ['2001:db8::1']]));
    }

    public function test_without_account_addresses_the_wildcard_listen_answers_on_the_gateway(): void
    {
        $this->assertSame('http://host.docker.internal:80', HostProxyOrigin::url(false, '*', ['ipv4' => [], 'ipv6' => []]));
    }
}
