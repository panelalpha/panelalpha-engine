<?php

namespace Tests\Unit\System;

use App\Exceptions\Handler;
use App\Exceptions\ProblemException;
use App\Lib\Project\ProjectIpAddresses;
use App\Models\Ipv4NatMap;
use App\Models\Setting;
use App\System\Network;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\NatHost;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * Which addresses `rebuildIpv4NatMaps()` asks about, where each lookup leaves from, and the results it refuses.
 * A lookup sent from core is answered with the default route's public address, whatever it asks about.
 */
class NetworkNatDiscoveryTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::clearRuntimeSettings();
        Http::fake(['*' => Http::response(TestNetwork::public(7))]);
    }

    public function test_bridges_veths_and_loopback_are_not_candidates(): void
    {
        $host = $this->hostBehindNat()
            ->withInterface('lo', TestNetwork::LOOPBACK, null, 'loopback')
            ->withInterface('docker0', TestNetwork::DOCKER_BRIDGE_GATEWAY, 'bridge')
            ->withInterface('br-pa-tenants', TestNetwork::container(1), 'bridge')
            ->withInterface('vethc0ffee', TestNetwork::container2(1), 'veth')
            ->withInterface('wg0', TestNetwork::private2(1), 'wireguard', 'none');
        Setting::set('default_ipv4', TestNetwork::public(7));

        (new Network($host))->rebuildIpv4NatMaps('https://lookup.test/ip');

        $this->assertSame([TestNetwork::private(5) => TestNetwork::public(7)], Ipv4NatMap::getLocalToPublicMap());
        $this->assertSame([TestNetwork::private(5)], $host->lookedUpFrom());
        $this->assertSame([TestNetwork::private(5)], ProjectIpAddresses::defaultBindIpAddresses()['ipv4']);
    }

    public function test_each_lookup_leaves_through_its_own_address_on_the_host(): void
    {
        $host = $this->hostBehindNat()
            ->withInterface('eth1', TestNetwork::private(6))
            ->answering(TestNetwork::private(6), FakeProcess::ok(TestNetwork::public(8)."\n"));

        (new Network($host))->rebuildIpv4NatMaps('https://lookup.test/ip');

        Http::assertNothingSent();
        $this->assertSame([TestNetwork::private(5), TestNetwork::private(6)], $host->lookedUpFrom());
        $this->assertSame([
            TestNetwork::private(5) => TestNetwork::public(7),
            TestNetwork::private(6) => TestNetwork::public(8),
        ], Ipv4NatMap::getLocalToPublicMap());
    }

    public function test_two_local_addresses_answered_with_one_public_address_are_refused(): void
    {
        $host = $this->hostBehindNat()
            ->withInterface('eth1', TestNetwork::private(6))
            ->answering(TestNetwork::private(6), FakeProcess::ok(TestNetwork::public(7)));

        $refusal = $this->refusal($host);

        $this->assertSame(409, $refusal->status);
        $this->assertSame($this->sharedMessage(TestNetwork::private(5), TestNetwork::private(6)), $refusal->getMessage());
        $this->assertSame([], Ipv4NatMap::getLocalToPublicMap());
    }

    public function test_a_public_address_a_stored_map_already_gives_out_is_refused_naming_that_map(): void
    {
        Ipv4NatMap::upsertMap(TestNetwork::private(4), TestNetwork::public(7));

        $refusal = $this->refusal($this->hostBehindNat());

        $this->assertSame(
            'Refusing to store the discovered IPv4 NAT maps: '.TestNetwork::private(5).' reaches the internet as '
            .TestNetwork::public(7).', which the stored NAT map '.TestNetwork::private(4).' => '.TestNetwork::public(7)
            .' already gives to another local address. Delete that map if it is stale, then run the discovery again. '
            .'No NAT map was changed and no site was rebound.',
            $refusal->getMessage(),
        );
        $this->assertSame([TestNetwork::private(4) => TestNetwork::public(7)], Ipv4NatMap::getLocalToPublicMap());
    }

    public function test_a_nic_masqueraded_out_of_an_address_that_answers_as_itself_is_refused(): void
    {
        $host = (new NatHost)
            ->withInterface('eth0', TestNetwork::public(7))
            ->withInterface('eth1', TestNetwork::private(2))
            ->answering(TestNetwork::public(7), FakeProcess::ok(TestNetwork::public(7)))
            ->answering(TestNetwork::private(2), FakeProcess::ok(TestNetwork::public(7)));
        Setting::set('default_ipv4', TestNetwork::public(7));

        $refusal = $this->refusal($host);

        $this->assertSame($this->sharedMessage(TestNetwork::public(7), TestNetwork::private(2)), $refusal->getMessage());
        $this->assertSame([], Ipv4NatMap::getLocalToPublicMap());
        $this->assertSame([TestNetwork::public(7)], ProjectIpAddresses::defaultBindIpAddresses()['ipv4']);
    }

    public function test_the_bridge_the_default_route_leaves_through_is_an_uplink(): void
    {
        $host = (new NatHost)
            ->withInterface('br0', TestNetwork::private(5), 'bridge')
            ->withInterface('docker0', TestNetwork::DOCKER_BRIDGE_GATEWAY, 'bridge')
            ->withDefaultRoute('br0')
            ->answering(TestNetwork::private(5), FakeProcess::ok(TestNetwork::public(7)));

        (new Network($host))->rebuildIpv4NatMaps('https://lookup.test/ip');

        $this->assertSame([TestNetwork::private(5)], $host->lookedUpFrom());
        $this->assertSame([TestNetwork::private(5) => TestNetwork::public(7)], Ipv4NatMap::getLocalToPublicMap());
    }

    #[DataProvider('interfacesNoDefaultRouteMakesAnUplink')]
    public function test_a_default_route_through_a_docker_bridge_or_a_tunnel_makes_no_uplink(string $name, string $kind, string $link): void
    {
        $host = (new NatHost)
            ->withInterface($name, TestNetwork::private(5), $kind, $link)
            ->withDefaultRoute($name)
            ->answering(TestNetwork::private(5), FakeProcess::ok(TestNetwork::public(7)));

        (new Network($host))->rebuildIpv4NatMaps('https://lookup.test/ip');

        $this->assertSame([], $host->lookedUpFrom());
        $this->assertSame([], Ipv4NatMap::getLocalToPublicMap());
    }

    /** @return array<string, array{string, string, string}> */
    public static function interfacesNoDefaultRouteMakesAnUplink(): array
    {
        return [
            'docker0' => ['docker0', 'bridge', 'ether'],
            'a compose bridge' => ['br-0d643b8d348c', 'bridge', 'ether'],
            'the tenant bridge' => ['br-pa-tenants', 'bridge', 'ether'],
            'wireguard' => ['wg0', 'wireguard', 'none'],
            'tun' => ['tun0', 'tun', 'none'],
            'dummy' => ['dummy0', 'dummy', 'ether'],
        ];
    }

    public function test_a_host_without_a_default_route_still_asks_from_its_nics(): void
    {
        $host = $this->hostBehindNat()->withInterface('br0', TestNetwork::private2(5), 'bridge');

        (new Network($host))->rebuildIpv4NatMaps('https://lookup.test/ip');

        $this->assertSame([TestNetwork::private(5)], $host->lookedUpFrom());
        $this->assertSame([TestNetwork::private(5) => TestNetwork::public(7)], Ipv4NatMap::getLocalToPublicMap());
    }

    public function test_the_refusal_prints_as_a_plain_line_on_the_console(): void
    {
        $host = $this->hostBehindNat()
            ->withInterface('eth1', TestNetwork::private(6))
            ->answering(TestNetwork::private(6), FakeProcess::ok(TestNetwork::public(7)));
        $output = new BufferedOutput;

        $this->assertTrue(Handler::renderPlainForConsole($output, $this->refusal($host)));

        $this->assertSame($this->sharedMessage(TestNetwork::private(5), TestNetwork::private(6))."\n", $output->fetch());
    }

    public function test_a_host_holding_its_public_address_ends_with_no_nat_maps(): void
    {
        $host = (new NatHost)
            ->withInterface('eth0', TestNetwork::public(7))
            ->withInterface('docker0', TestNetwork::DOCKER_BRIDGE_GATEWAY, 'bridge')
            ->withInterface('br-pa-tenants', TestNetwork::container(1), 'bridge')
            ->answering(TestNetwork::public(7), FakeProcess::ok(TestNetwork::public(7)));
        Setting::set('default_ipv4', TestNetwork::public(7));

        $result = (new Network($host))->rebuildIpv4NatMaps('https://lookup.test/ip', true);

        $this->assertSame([], $result['maps']);
        $this->assertFalse(Ipv4NatMap::isNatModeEnabled());
        $this->assertSame([TestNetwork::public(7)], ProjectIpAddresses::defaultBindIpAddresses()['ipv4']);
    }

    public function test_a_stored_map_onto_an_address_the_host_holds_is_removed(): void
    {
        Ipv4NatMap::upsertMap(TestNetwork::DOCKER_BRIDGE_GATEWAY, TestNetwork::public(7));
        $host = (new NatHost)
            ->withInterface('eth0', TestNetwork::public(7))
            ->withInterface('docker0', TestNetwork::DOCKER_BRIDGE_GATEWAY, 'bridge')
            ->withInterface('br-pa-tenants', TestNetwork::container(1), 'bridge')
            ->answering(TestNetwork::public(7), FakeProcess::ok(TestNetwork::public(7)));
        Setting::set('default_ipv4', TestNetwork::public(7));

        (new Network($host))->rebuildIpv4NatMaps('https://lookup.test/ip', true);

        $this->assertSame([], Ipv4NatMap::getLocalToPublicMap());
        $this->assertSame(TestNetwork::public(7), Setting::get('default_ipv4'));
        $this->assertSame([TestNetwork::public(7)], ProjectIpAddresses::defaultBindIpAddresses()['ipv4']);
    }

    public function test_a_hand_made_map_from_a_public_host_address_survives(): void
    {
        Ipv4NatMap::upsertMap(TestNetwork::public(7), TestNetwork::public2(9));
        $host = (new NatHost)
            ->withInterface('eth0', TestNetwork::public(7))
            ->answering(TestNetwork::public(7), FakeProcess::ok(TestNetwork::public(7)));

        (new Network($host))->rebuildIpv4NatMaps('https://lookup.test/ip');

        $this->assertSame([TestNetwork::public(7) => TestNetwork::public2(9)], Ipv4NatMap::getLocalToPublicMap());
    }

    public function test_a_map_an_earlier_discovery_stored_for_a_docker_bridge_goes_before_the_shared_address_check(): void
    {
        Ipv4NatMap::upsertMap(TestNetwork::DOCKER_BRIDGE_GATEWAY, TestNetwork::public(7));
        Ipv4NatMap::upsertMap(TestNetwork::container(1), TestNetwork::public(8));
        $host = $this->hostBehindNat()
            ->withInterface('docker0', TestNetwork::DOCKER_BRIDGE_GATEWAY, 'bridge')
            ->withInterface('br-pa-tenants', TestNetwork::container(1), 'bridge');
        Setting::set('default_ipv4', TestNetwork::public(7));

        (new Network($host))->rebuildIpv4NatMaps('https://lookup.test/ip');

        $this->assertSame([TestNetwork::private(5) => TestNetwork::public(7)], Ipv4NatMap::getLocalToPublicMap());
        $this->assertSame([TestNetwork::private(5)], ProjectIpAddresses::defaultBindIpAddresses()['ipv4']);
    }

    public function test_a_refused_discovery_keeps_the_maps_from_docker_bridges_too(): void
    {
        Ipv4NatMap::upsertMap(TestNetwork::DOCKER_BRIDGE_GATEWAY, TestNetwork::public(7));
        $host = $this->hostBehindNat()
            ->withInterface('docker0', TestNetwork::DOCKER_BRIDGE_GATEWAY, 'bridge')
            ->withInterface('eth1', TestNetwork::private(6))
            ->answering(TestNetwork::private(6), FakeProcess::ok(TestNetwork::public(7)));

        $refusal = $this->refusal($host);

        $this->assertSame($this->sharedMessage(TestNetwork::private(5), TestNetwork::private(6)), $refusal->getMessage());
        $this->assertSame([TestNetwork::DOCKER_BRIDGE_GATEWAY => TestNetwork::public(7)], Ipv4NatMap::getLocalToPublicMap());
    }

    public function test_maps_from_a_tunnel_or_a_dummy_interface_survive_discovery(): void
    {
        Ipv4NatMap::upsertMap(TestNetwork::DOCKER_BRIDGE_GATEWAY, TestNetwork::public(7));
        Ipv4NatMap::upsertMap(TestNetwork::private2(1), TestNetwork::public(9));
        Ipv4NatMap::upsertMap(TestNetwork::private2(2), TestNetwork::public(10));
        $host = $this->hostBehindNat()
            ->withInterface('docker0', TestNetwork::DOCKER_BRIDGE_GATEWAY, 'bridge')
            ->withInterface('wg0', TestNetwork::private2(1), 'wireguard', 'none')
            ->withInterface('dummy0', TestNetwork::private2(2), 'dummy');

        (new Network($host))->rebuildIpv4NatMaps('https://lookup.test/ip');

        $this->assertSame([
            TestNetwork::private2(1) => TestNetwork::public(9),
            TestNetwork::private2(2) => TestNetwork::public(10),
            TestNetwork::private(5) => TestNetwork::public(7),
        ], Ipv4NatMap::getLocalToPublicMap());
    }

    public function test_a_hand_made_map_from_an_uplink_address_no_lookup_answers_for_survives(): void
    {
        Ipv4NatMap::upsertMap(TestNetwork::private(6), TestNetwork::public(8));
        $host = $this->hostBehindNat()
            ->withInterface('eth1', TestNetwork::private(6))
            ->withInterface('docker0', TestNetwork::DOCKER_BRIDGE_GATEWAY, 'bridge');

        (new Network($host))->rebuildIpv4NatMaps('https://lookup.test/ip');

        $this->assertSame([
            TestNetwork::private(6) => TestNetwork::public(8),
            TestNetwork::private(5) => TestNetwork::public(7),
        ], Ipv4NatMap::getLocalToPublicMap());
    }

    public function test_forgetting_docker_maps_removes_only_those_from_addresses_on_dockers_own_interfaces(): void
    {
        Ipv4NatMap::upsertMap(TestNetwork::private(5), TestNetwork::public(7));
        Ipv4NatMap::upsertMap(TestNetwork::DOCKER_BRIDGE_GATEWAY, TestNetwork::public(8));
        Ipv4NatMap::upsertMap(TestNetwork::container(1), TestNetwork::public(9));
        Ipv4NatMap::upsertMap(TestNetwork::container(2), TestNetwork::public(10));
        Ipv4NatMap::upsertMap(TestNetwork::container2(1), TestNetwork::public(11));
        Ipv4NatMap::upsertMap(TestNetwork::private2(1), TestNetwork::public(12));
        Ipv4NatMap::upsertMap(TestNetwork::private2(2), TestNetwork::public(13));
        Ipv4NatMap::upsertMap(TestNetwork::private(4), TestNetwork::public(14));
        $host = (new NatHost)
            ->withInterface('eth0', TestNetwork::private(5))
            ->withInterface('docker0', TestNetwork::DOCKER_BRIDGE_GATEWAY, 'bridge')
            ->withInterface('br-pa-tenants', TestNetwork::container(1), 'bridge')
            ->withInterface('br-0d643b8d348c', TestNetwork::container(2), 'bridge')
            ->withInterface('vethc0ffee', TestNetwork::container2(1), 'veth')
            ->withInterface('wg0', TestNetwork::private2(1), 'wireguard', 'none')
            ->withInterface('dummy0', TestNetwork::private2(2), 'dummy');

        $forgotten = (new Network($host))->forgetDockerIpv4NatMaps();

        $this->assertSame(
            [TestNetwork::DOCKER_BRIDGE_GATEWAY, TestNetwork::container(1), TestNetwork::container(2), TestNetwork::container2(1)],
            array_map(static fn (Ipv4NatMap $map): string => $map->local_ip, $forgotten),
        );
        $this->assertSame([
            TestNetwork::private(5) => TestNetwork::public(7),
            TestNetwork::private2(1) => TestNetwork::public(12),
            TestNetwork::private2(2) => TestNetwork::public(13),
            TestNetwork::private(4) => TestNetwork::public(14),
        ], Ipv4NatMap::getLocalToPublicMap());
        $this->assertSame([], $host->lookedUpFrom());
    }

    public function test_forgetting_docker_maps_keeps_every_map_when_the_interfaces_cannot_be_read(): void
    {
        Ipv4NatMap::upsertMap(TestNetwork::DOCKER_BRIDGE_GATEWAY, TestNetwork::public(7));
        $host = (new NatHost)
            ->withInterface('docker0', TestNetwork::DOCKER_BRIDGE_GATEWAY, 'bridge')
            ->readingInterfacesAs(FakeProcess::failed('pa-root: the root broker is not reachable'));

        $failure = null;
        try {
            (new Network($host))->forgetDockerIpv4NatMaps();
        } catch (\Exception $e) {
            $failure = $e->getMessage();
        }

        $this->assertSame("Could not read the host's interfaces: pa-root: the root broker is not reachable", $failure);
        $this->assertSame([TestNetwork::DOCKER_BRIDGE_GATEWAY => TestNetwork::public(7)], Ipv4NatMap::getLocalToPublicMap());
    }

    private function hostBehindNat(): NatHost
    {
        return (new NatHost)
            ->withInterface('eth0', TestNetwork::private(5))
            ->answering(TestNetwork::private(5), FakeProcess::ok(TestNetwork::public(7)));
    }

    private function refusal(NatHost $host): ProblemException
    {
        try {
            (new Network($host))->rebuildIpv4NatMaps('https://lookup.test/ip');
        } catch (ProblemException $e) {
            return $e;
        }

        $this->fail('Discovery stored a result that maps two local addresses to one public address.');
    }

    private function sharedMessage(string $first, string $second): string
    {
        return 'Refusing to store the discovered IPv4 NAT maps: '.$first.' and '.$second.' reach the internet as the same '
            .'public address '.TestNetwork::public(7).'. A 1:1 NAT gives each public address to one local address, so this host '
            .'shares one public address between several, or the lookup service does not answer with the address '
            .'a request came from. No NAT map was changed and no site was rebound.';
    }
}
