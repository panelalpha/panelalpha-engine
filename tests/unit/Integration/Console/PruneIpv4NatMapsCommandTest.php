<?php

namespace Tests\Integration\Console;

use App\Models\Ipv4NatMap;
use App\System;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\NatHost;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * `system:nat:prune` is what an update runs, NAT rebuilt or not, so the maps an earlier discovery stored for Docker's
 * bridges stop deciding where the sites bind.
 */
class PruneIpv4NatMapsCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_the_maps_from_docker_bridges_go_and_the_sites_rebind(): void
    {
        Ipv4NatMap::upsertMap(TestNetwork::private(5), TestNetwork::public(7));
        Ipv4NatMap::upsertMap(TestNetwork::DOCKER_BRIDGE_GATEWAY, TestNetwork::public(8));
        Ipv4NatMap::upsertMap(TestNetwork::private2(1), TestNetwork::public(9));
        $host = $this->host();

        $exit = Artisan::call('system:nat:prune');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString(
            'Removed the IPv4 NAT map '.TestNetwork::DOCKER_BRIDGE_GATEWAY.' => '.TestNetwork::public(8),
            Artisan::output(),
        );
        $this->assertSame([
            TestNetwork::private(5) => TestNetwork::public(7),
            TestNetwork::private2(1) => TestNetwork::public(9),
        ], Ipv4NatMap::getLocalToPublicMap());
        $this->assertSame(1, $host->domainRebuilds);
    }

    public function test_nothing_to_remove_leaves_the_webserver_alone(): void
    {
        Ipv4NatMap::upsertMap(TestNetwork::private(5), TestNetwork::public(7));
        Ipv4NatMap::upsertMap(TestNetwork::private2(1), TestNetwork::public(9));
        $host = $this->host();

        $exit = Artisan::call('system:nat:prune');

        $this->assertSame(0, $exit);
        $this->assertSame([
            TestNetwork::private(5) => TestNetwork::public(7),
            TestNetwork::private2(1) => TestNetwork::public(9),
        ], Ipv4NatMap::getLocalToPublicMap());
        $this->assertSame(0, $host->domainRebuilds);
    }

    public function test_a_host_whose_interfaces_cannot_be_read_keeps_every_map_and_exits_non_zero(): void
    {
        Ipv4NatMap::upsertMap(TestNetwork::DOCKER_BRIDGE_GATEWAY, TestNetwork::public(7));
        $host = $this->host()->readingInterfacesAs(FakeProcess::failed('pa-root: the root broker is not reachable'));

        $exit = Artisan::call('system:nat:prune');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("Could not read the host's interfaces: pa-root: the root broker is not reachable", Artisan::output());
        $this->assertSame([TestNetwork::DOCKER_BRIDGE_GATEWAY => TestNetwork::public(7)], Ipv4NatMap::getLocalToPublicMap());
        $this->assertSame(0, $host->domainRebuilds);
    }

    private function host(): NatHost
    {
        $host = (new NatHost)
            ->withInterface('eth0', TestNetwork::private(5))
            ->withInterface('docker0', TestNetwork::DOCKER_BRIDGE_GATEWAY, 'bridge')
            ->withInterface('wg0', TestNetwork::private2(1), 'wireguard', 'none');
        $this->app->instance(System::class, $host);

        return $host;
    }
}
