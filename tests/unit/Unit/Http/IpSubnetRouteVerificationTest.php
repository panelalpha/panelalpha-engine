<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\IpSubnet;
use App\System;
use App\System\Network;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** `POST /ip/subnets` stores a subnet only once the host routes it, as `POST /ip/assign` does for an address. */
class IpSubnetRouteVerificationTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        foreach (['defaultIpv4Route', 'defaultIpv6Route'] as $property) {
            (new \ReflectionProperty(Network::class, $property))->setValue(null, null);
        }
    }

    public function test_an_ipv6_subnet_on_a_host_without_an_ipv6_route_is_refused_and_not_stored(): void
    {
        $this->app->instance(System::class, $this->host(ipv6Default: false, routed: []));

        $response = $this->postJson('/api/ip/subnets', ['ip' => '2001:db8:f220::', 'mask' => 64]);

        $response->assertStatus(422);
        $this->assertSame(
            'Could not add the host route for subnet 2001:db8:f220::/64: No default route found for IPv6',
            $response->json('errors.ip.0')
        );
        $this->assertSame(0, IpSubnet::query()->count());
    }

    public function test_a_route_the_host_did_not_take_is_refused_with_its_cause(): void
    {
        $this->app->instance(System::class, $this->host(ipv6Default: true, routed: [], addError: 'RTNETLINK answers: Network is unreachable'));

        $response = $this->postJson('/api/ip/subnets', ['ip' => '2001:db8:f220::', 'mask' => 64]);

        $response->assertStatus(422);
        $this->assertSame(
            'The host does not route 2001:db8:f220::/64 after adding it, so the subnet was not added: RTNETLINK answers: Network is unreachable',
            $response->json('errors.ip.0')
        );
        $this->assertSame(0, IpSubnet::query()->count());
    }

    public function test_a_routed_subnet_is_stored(): void
    {
        $host = $this->host(ipv6Default: true, routed: ['2001:db8:f220::/64']);
        $this->app->instance(System::class, $host);

        $this->postJson('/api/ip/subnets', ['ip' => '2001:db8:f220::', 'mask' => 64])->assertStatus(201);

        $this->assertSame(1, IpSubnet::query()->count());
        $this->assertContains(['ip', 'route', 'add', '2001:db8:f220::/64', 'via', 'fe80::1', 'dev', 'eth0'], $host->commands);
    }

    /** `ip route add` exits 2 on a route that is already there; the host routes the subnet all the same. */
    public function test_a_route_that_was_already_there_is_accepted(): void
    {
        $this->app->instance(System::class, $this->host(ipv6Default: false, routed: [TestNetwork::public3(0).'/24'], addError: 'RTNETLINK answers: File exists'));

        $this->postJson('/api/ip/subnets', ['ip' => TestNetwork::public3(0), 'mask' => 24])->assertStatus(201);

        $this->assertSame(1, IpSubnet::query()->count());
    }

    /**
     * @param list<string> $routed prefixes `ip route show <prefix>` lists
     */
    private function host(bool $ipv6Default, array $routed, ?string $addError = null): System
    {
        return new class ($ipv6Default, $routed, $addError) extends System {
            /** @var list<list<string>> */
            public array $commands = [];

            /** @param list<string> $routed */
            public function __construct(
                private bool $ipv6Default,
                private array $routed,
                private ?string $addError,
            ) {
            }

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
                $this->commands[] = $argv;
                $joined = implode(' ', $argv);

                return match (true) {
                    $joined === 'ip route show default' => FakeProcess::ok('default via '.TestNetwork::private(1)." dev eth0 proto dhcp metric 100\n"),
                    $joined === 'ip -6 route show default' => FakeProcess::ok($this->ipv6Default ? "default via fe80::1 dev eth0 proto ra metric 1024\n" : ''),
                    str_contains($joined, 'route add') => $this->addError === null ? FakeProcess::ok() : FakeProcess::failed($this->addError, 2),
                    str_contains($joined, 'route show') => FakeProcess::ok(in_array(end($argv), $this->routed, true) ? end($argv) . " via gw dev eth0\n" : ''),
                    default => FakeProcess::ok(),
                };
            }
        };
    }
}
