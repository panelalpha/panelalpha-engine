<?php

namespace Tests\Integration\Ip;

use App\Lib\Ip\HostIpCleanup;
use App\Models\IpAssigned;
use App\Models\IpSubnet;
use App\System;
use App\System\Network;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\HostCommand;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** Host cleanup after unassign and subnet delete is best-effort: every way it can fail is logged and reported, success is silent. */
class HostIpCleanupTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        foreach (['defaultIpv4Route', 'defaultIpv6Route'] as $property) {
            (new \ReflectionProperty(Network::class, $property))->setValue(null, null);
        }
        Log::spy();
    }

    protected function tearDown(): void
    {
        foreach (['ip_assigned', 'ip_subnets', 'domains', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_a_route_the_host_refused_is_logged_with_its_exit_code_and_error(): void
    {
        $host = $this->host(defaultRoute: 'default via '.TestNetwork::private(1).' dev eth0', delete: FakeProcess::failed("RTNETLINK answers: No such process\n", 2));

        $this->assertFalse((new HostIpCleanup($host))->removeRoute($this->subnet()));

        $this->assertContains(['ip', 'route', 'del', TestNetwork::public3(0).'/29', 'via', TestNetwork::private(1), 'dev', 'eth0'], $host->commands);
        Log::shouldHaveReceived('warning')
            ->with('Could not delete IP route for subnet '.TestNetwork::public3(0).'/29', ['exit_code' => 2, 'error_output' => 'RTNETLINK answers: No such process'])
            ->once();
    }

    public function test_an_address_whose_command_cannot_be_built_is_logged_with_the_exception(): void
    {
        $host = $this->host(defaultRoute: '', delete: FakeProcess::ok());

        $this->assertFalse((new HostIpCleanup($host))->removeUnusedAddress(new IpAssigned(['ip_address' => TestNetwork::public3(2)]), $this->subnet()));

        $this->assertSame([['ip', 'route', 'show', 'default']], $host->commands);
        Log::shouldHaveReceived('warning')
            ->with('Could not delete IP address '.TestNetwork::public3(2).' from network interface', ['exception_message' => 'No default route found for IPv4'])
            ->once();
    }

    public function test_a_cleanup_the_host_took_logs_nothing(): void
    {
        $host = $this->host(defaultRoute: 'default via '.TestNetwork::private(1).' dev eth0', delete: FakeProcess::ok());

        $this->assertTrue((new HostIpCleanup($host))->removeUnusedAddress(new IpAssigned(['ip_address' => TestNetwork::public3(2)]), $this->subnet()));

        $this->assertContains(['ip', 'addr', 'del', TestNetwork::public3(2).'/29', 'dev', 'eth0'], $host->commands);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_shared_address_another_project_still_holds_stays_on_the_host(): void
    {
        $subnet = IpSubnet::create(['ip' => TestNetwork::public3(0), 'mask' => 29, 'family' => 4, 'is_shared' => 1]);
        $other = $this->makeUser('bob');
        IpAssigned::create(['user_id' => $other->id, 'ip_subnet_id' => $subnet->id, 'ip_address' => TestNetwork::public3(2)]);
        $host = $this->host(defaultRoute: 'default via '.TestNetwork::private(1).' dev eth0', delete: FakeProcess::ok());

        $this->assertTrue((new HostIpCleanup($host))->removeUnusedAddress(new IpAssigned(['ip_address' => TestNetwork::public3(2)]), $subnet));

        $this->assertSame([], $host->commands);
        Log::shouldNotHaveReceived('warning');
    }

    private function subnet(): IpSubnet
    {
        return new IpSubnet(['ip' => TestNetwork::public3(0), 'mask' => 29, 'family' => 4, 'is_shared' => 0]);
    }

    private function host(string $defaultRoute, Process $delete): System
    {
        return new class ($defaultRoute, $delete) extends System {
            /** @var list<list<string>> */
            public array $commands = [];

            public function __construct(private string $defaultRoute, private Process $delete) {}

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $cmd = HostCommand::argv($cmd);
                $argv = array_values((array) $cmd);
                $this->commands[] = $argv;

                return $argv === ['ip', 'route', 'show', 'default'] ? FakeProcess::ok($this->defaultRoute) : $this->delete;
            }
        };
    }
}
