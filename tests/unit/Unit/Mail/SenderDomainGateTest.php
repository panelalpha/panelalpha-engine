<?php

namespace Tests\Unit\Mail;

use App\Lib\Mail\SenderDomainGate;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * A project may send as a domain only when the engine can show the domain is this server's; attaching one checks its
 * format and nothing else.
 */
class SenderDomainGateTest extends TestCase
{
    /** @var list<string> */
    private array $asked = [];

    public function test_an_attached_domain_that_resolves_elsewhere_is_refused(): void
    {
        $gate = $this->gate(['bank-example.com' => [TestNetwork::public2(80)]]);

        $this->assertFalse($gate->allows('bank-example.com'));
        $this->assertFalse($gate->allows('nowhere.example'), 'a domain that does not resolve is nobody\'s here');
    }

    public function test_a_domain_whose_a_or_aaaa_record_names_this_server_is_allowed(): void
    {
        $gate = $this->gate([
            'shop.example' => [TestNetwork::public2(80), TestNetwork::public(10)],
            'v6.example' => ['2001:0db8:0:0::10'],
        ]);

        $this->assertTrue($gate->allows('shop.example'));
        $this->assertTrue($gate->allows('Shop.Example.'), 'a domain is matched without case or a trailing dot');
        $this->assertTrue($gate->allows('v6.example'), 'an IPv6 address is compared as an address, not as text');
    }

    public function test_a_name_below_one_of_the_engines_bases_is_allowed_without_asking_dns(): void
    {
        $gate = $this->gate([]);

        $this->assertTrue($gate->allows('shop.sites.host.example'));
        $this->assertTrue($gate->allows('alice.198-51-100-10.panelalpha.direct'));
        $this->assertSame([], $this->asked);
    }

    /** The base itself is the engine's own name, the one every other sender is rewritten to. */
    public function test_a_base_itself_is_not_a_projects_to_send_as(): void
    {
        $gate = $this->gate([]);

        $this->assertFalse($gate->allows('sites.host.example'));
        $this->assertFalse($gate->allows('evilsites.host.example'), 'below means a whole label, not a suffix');
    }

    /** One label of the zone is held by one engine fleet-wide: only a tunnel shows it is this project's. */
    public function test_a_panelalpha_online_name_counts_only_when_a_tunnel_of_the_project_holds_it(): void
    {
        $gate = $this->gate(['shop-ab12.panelalpha.online' => [TestNetwork::public(10)]]);

        $this->assertFalse($gate->allows('shop-ab12.panelalpha.online'));
        $this->assertTrue($gate->allows('shop-ab12.panelalpha.online', ['Shop-AB12.panelalpha.online']));
        $this->assertSame([], $this->asked, 'the zone answers for every label, so DNS proves nothing there');
    }

    public function test_a_name_a_cloudflare_tunnel_of_the_project_holds_is_allowed(): void
    {
        $gate = $this->gate(['shop.example.com' => [TestNetwork::public2(80)]]);

        $this->assertTrue($gate->allows('shop.example.com', ['shop.example.com']));
        $this->assertFalse($gate->allows('other.example.com', ['shop.example.com']));
    }

    public function test_an_empty_name_is_refused(): void
    {
        $this->assertFalse($this->gate([])->allows(' . '));
    }

    /** @param array<string, list<string>> $dns */
    private function gate(array $dns): SenderDomainGate
    {
        return new SenderDomainGate(
            ['sites.host.example', '198-51-100-10.panelalpha.direct'],
            [TestNetwork::public(10), '2001:db8::10'],
            function (string $domain) use ($dns): array {
                $this->asked[] = $domain;

                return $dns[$domain] ?? [];
            }
        );
    }
}
