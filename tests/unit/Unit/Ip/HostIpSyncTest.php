<?php

namespace Tests\Unit\Ip;

use App\Lib\Ip\HostIpSync;
use App\Models\IpAssigned;
use App\Models\IpSubnet;
use App\System;
use App\System\Network;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\HostCommand;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * `system:ip:sync` looks a family's default route up only for a subnet of that family, and a subnet it could not put
 * back fails the command unless --skip-errors is given.
 */
class HostIpSyncTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        foreach (['defaultIpv4Route', 'defaultIpv6Route'] as $property) {
            (new \ReflectionProperty(Network::class, $property))->setValue(null, null);
        }
    }

    protected function tearDown(): void
    {
        foreach (['ip_assigned', 'ip_subnets', 'domains', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_a_host_without_ipv6_and_no_ipv6_subnet_is_not_asked_for_an_ipv6_route(): void
    {
        $this->ipv4Subnet();
        $host = $this->host();

        $lines = (new HostIpSync($host))->sync(false);

        $this->assertSame([true, true], array_column($lines, 'ok'));
        $this->assertStringContainsString('Added IP route for '.TestNetwork::public3(0).'/29', $lines[0]['message']);
        $this->assertStringContainsString('Added IP address '.TestNetwork::public3(2), $lines[1]['message']);
        $lookups = array_filter($host->commands, static fn (array $argv): bool => in_array('default', $argv, true));
        $this->assertSame([['ip', 'route', 'show', 'default']], array_values($lookups));
    }

    public function test_a_subnet_whose_family_has_no_default_route_is_a_failure(): void
    {
        IpSubnet::create(['ip' => '2001:db8::', 'mask' => 120, 'family' => 6, 'is_shared' => 0]);

        $lines = (new HostIpSync($this->host()))->sync(false);

        $this->assertSame([
            ['ok' => false, 'message' => 'Could not get default IPv6 route: No default route found for IPv6'],
            ['ok' => false, 'message' => 'Skipping subnet 2001:db8::/120 due to missing route.'],
        ], $lines);
    }

    public function test_the_command_exits_zero_when_every_subnet_is_put_back(): void
    {
        $this->ipv4Subnet();
        $this->app->instance(System::class, $this->host());

        $this->assertSame(0, Artisan::call('system:ip:sync'));
        $this->assertStringNotContainsString('IPv6', Artisan::output());
    }

    public function test_the_command_fails_on_a_subnet_it_could_not_put_back_unless_told_to_skip_errors(): void
    {
        IpSubnet::create(['ip' => '2001:db8::', 'mask' => 120, 'family' => 6, 'is_shared' => 0]);
        $this->app->instance(System::class, $this->host());

        $this->assertSame(1, Artisan::call('system:ip:sync'));
        $this->assertStringContainsString('Could not get default IPv6 route', Artisan::output());
        $this->assertSame(0, Artisan::call('system:ip:sync', ['--skip-errors' => true]));
    }

    private function ipv4Subnet(): void
    {
        $subnet = IpSubnet::create(['ip' => TestNetwork::public3(0), 'mask' => 29, 'family' => 4, 'is_shared' => 0]);
        IpAssigned::create(['user_id' => $this->makeUser('alice')->id, 'ip_subnet_id' => $subnet->id, 'ip_address' => TestNetwork::public3(2)]);
    }

    /**
     * A host with an IPv4 default route and none for IPv6.
     *
     * @return System&object{commands: list<list<string>>}
     */
    private function host(): System
    {
        return new class extends System
        {
            /** @var list<list<string>> */
            public array $commands = [];

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = (array) HostCommand::argv($cmd);
                $this->commands[] = $argv;

                return $argv === ['ip', 'route', 'show', 'default']
                    ? FakeProcess::ok('default via '.TestNetwork::private(1).' dev eth0')
                    : FakeProcess::ok();
            }
        };
    }
}
